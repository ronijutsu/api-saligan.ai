<?php

namespace App\Http\Controllers;

use App\Ai\Tools\CreateTodoTool;
use App\Ai\Tools\FlagAdvisoriesTool;
use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Jobs\CaptureCitedLegalPage;
use App\Models\Advisory;
use App\Models\AiUsage;
use App\Models\Conversation;
use App\Models\MatterMemory;
use App\Models\Message;
use App\Models\Template;
use App\Services\Ai\PythonConversationContext;
use App\Services\Billing\AiBudget;
use App\Services\MatterMemory\MatterMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request as ToolRequest;

class InternalAiController extends Controller
{
    public function __construct(
        private readonly PythonConversationContext $context,
        private readonly MatterMemoryService $memory,
    ) {}

    public function context(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'current_message' => ['sometimes', 'nullable', 'string', 'max:8000'],
        ]);

        return response()->json($this->context->for($conversation, $validated['current_message'] ?? null));
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'message_id' => ['required', 'uuid'],
            'provider' => ['nullable', Rule::enum(ChatProvider::class)],
            'reservation_id' => ['nullable', 'uuid'],
            'user' => ['required', 'array'],
            'user.content' => ['required', 'string', 'max:8000'],
            'user.attachment_ids' => ['sometimes', 'array', 'max:10'],
            'user.attachment_ids.*' => ['uuid'],
            'assistant' => ['required', 'array'],
            'assistant.content' => ['present', 'string'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $result = DB::transaction(function () use ($conversation, $validated): array {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            // Idempotency belongs to this conversation. A UUID supplied by a
            // different turn must never make this callback silently report
            // success for another thread.
            $existing = Message::query()->find($validated['message_id']);

            if ($existing !== null) {
                if ((string) $existing->conversation_id !== (string) $conversation->id) {
                    abort(409, 'Message id belongs to another conversation.');
                }

                if ($existing->role !== MessageRole::Assistant) {
                    abort(409, 'Message id belongs to another message.');
                }

                return [
                    'ok' => true,
                    'message_id' => $existing->id,
                    'idempotent' => true,
                ];
            }

            $attachmentIds = array_values($validated['user']['attachment_ids'] ?? []);
            $userMessage = Message::create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::User,
                'content' => $validated['user']['content'],
                'metadata' => $attachmentIds === [] ? null : ['attachment_ids' => $attachmentIds],
            ]);

            $metadata = $validated['metadata'] ?? [];
            $provider = ChatProvider::tryFrom($validated['provider'] ?? '');
            $assistantMessage = Message::create([
                'id' => $validated['message_id'],
                'conversation_id' => $conversation->id,
                'role' => MessageRole::Assistant,
                'content' => trim($validated['assistant']['content']),
                'provider' => $provider,
                'cited_chunk_ids' => $metadata['document_chunk_ids'] ?? [],
                'cited_legal_chunk_ids' => $metadata['legal_chunk_ids'] ?? [],
                'cited_standard_chunk_ids' => $metadata['standard_chunk_ids'] ?? [],
                'metadata' => collect($metadata)
                    ->except(['document_chunk_ids', 'legal_chunk_ids', 'standard_chunk_ids'])
                    ->all(),
            ]);

            // Settle this turn's spend hold with the usage the engine
            // reported. Idempotent with the message row above: a retried
            // callback finds the existing message and returns before this.
            self::settleTurnUsage($conversation, $validated, $metadata);

            Advisory::query()
                ->where('conversation_id', $conversation->id)
                ->whereNull('message_id')
                ->update(['message_id' => $assistantMessage->id]);

            // ai-provider chooses who answers, so the conversation records
            // the provider that last actually did.
            if ($provider !== null) {
                $conversation->update(['provider' => $provider]);
            }

            if ($conversation->title === null) {
                $title = collect(preg_split('/\R/', $assistantMessage->content) ?: [])
                    ->map(fn (string $line): string => trim($line, " \t\n\r#*"))
                    ->first(fn (string $line): bool => $line !== '');

                $conversation->update([
                    'title' => Str::limit($title ?: $validated['user']['content'], 60),
                ]);
            }

            return [
                'ok' => true,
                'user_message_id' => $userMessage->id,
                'message_id' => $assistantMessage->id,
                'idempotent' => false,
                'web_citations' => $metadata['web_citations'] ?? [],
            ];
        });

        if (! $result['idempotent']) {
            $this->captureCitedPages($result['web_citations']);
        }

        return response()->json(Arr::except($result, 'web_citations'));
    }

    /**
     * Queue a capture for each official legal page the answer cited from the
     * web, so the next reader finds it in the knowledge base.
     */
    protected function captureCitedPages(mixed $webCitations): void
    {
        foreach (is_array($webCitations) ? $webCitations : [] as $citation) {
            $url = is_array($citation) ? ($citation['url'] ?? null) : null;

            if (! is_string($url) || $url === '' || ! CaptureCitedLegalPage::shouldCapture($url)) {
                continue;
            }

            CaptureCitedLegalPage::dispatch($url)->onQueue(config('saligan.crawler.queue'));
        }
    }

    /**
     * Settle the turn's spend reservation from the engine-reported usage.
     *
     * Runs inside the persist transaction so a settled ledger row and its
     * message can never part ways. Unknown or missing usage settles the
     * pre-agreed hold rather than nothing — the turn demonstrably ran.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $metadata
     */
    protected static function settleTurnUsage(Conversation $conversation, array $validated, array $metadata): void
    {
        $summary = $metadata['usage'] ?? null;
        $summary = is_array($summary) ? $summary : [];

        if (($validated['reservation_id'] ?? null) !== null) {
            $reservation = AiUsage::query()->find($validated['reservation_id']);

            // The reservation must belong to this conversation: a mismatched
            // id is a wiring bug, and settling a stranger's hold would
            // corrupt both turns' accounting.
            if ($reservation !== null && (string) $reservation->conversation_id === (string) $conversation->id) {
                AiBudget::settleTurn($reservation, $summary, [
                    'provider' => $validated['provider'] ?? null,
                ]);

                return;
            }
        }

        // No reservation (older callers, direct posts): record the measured
        // spend straight onto the window so the ledger stays complete.
        $user = $conversation->user;

        if ($user === null) {
            return;
        }

        $reservation = AiBudget::reserve($user, AiUsage::OPERATION_CHAT, context: [
            'conversation_id' => $conversation->id,
            'engine' => 'python',
        ]);

        AiBudget::settleTurn($reservation, $summary, [
            'provider' => $validated['provider'] ?? null,
        ]);
    }

    public function todos(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:25'],
            'items.*' => ['array'],
            'tool_call_id' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->toolResult(
            $conversation,
            'todos',
            $validated['tool_call_id'] ?? null,
            fn (): string => (new CreateTodoTool($conversation->id))->handle(
                new ToolRequest(['items' => $validated['items']], $validated['tool_call_id'] ?? null),
            ),
        ));
    }

    public function advisories(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:12'],
            'items.*' => ['array'],
            'tool_call_id' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->toolResult(
            $conversation,
            'advisories',
            $validated['tool_call_id'] ?? null,
            fn (): string => (new FlagAdvisoriesTool($conversation->id))->handle(
                new ToolRequest(['items' => $validated['items']], $validated['tool_call_id'] ?? null),
            ),
        ));
    }

    public function letters(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required_without:template_fields', 'array'],
            'content.type' => ['required_with:content', 'in:doc'],
            'content.content' => ['required_with:content', 'array'],
            'template_fields' => ['required_without:content', 'array'],
            'template_fields.*' => ['string', 'max:10000'],
            'template_id' => ['nullable', 'uuid'],
            'tool_call_id' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['template_id'] ?? null) !== null) {
            $template = Template::query()
                ->visibleTo($conversation->user)
                ->whereKey($validated['template_id'])
                ->first();

            abort_unless($template?->isVerbatimTemplate(), 422, 'The selected template is not a verbatim template.');
        }

        return response()->json(array_filter([
            'ok' => true,
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
            'template_fields' => $validated['template_fields'] ?? null,
            'template_id' => $validated['template_id'] ?? null,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function memory(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'facts' => ['required', 'array', 'max:50'],
            'facts.*' => ['required', 'string', 'max:5000'],
            'tool_call_id' => ['nullable', 'string', 'max:255'],
        ]);

        $case = $conversation->case;
        $user = $conversation->user;

        if ($case === null || $user === null || ! $this->memory->canWrite($case)) {
            return response()->json(['ok' => true, 'accepted' => 0, 'items' => []]);
        }

        $created = [];

        foreach ($validated['facts'] as $fact) {
            [$type, $content] = $this->memoryFact($fact, $case->id);

            if ($content === '' || ! in_array($type, MatterMemory::TYPES, true)) {
                continue;
            }

            if ($this->memory->existsSimilar($case, $type, $content)) {
                continue;
            }

            $entry = $this->memory->store($case, $user, $type, $content);
            $created[] = ['id' => $entry->id, 'type' => $entry->type, 'content' => $entry->content];
        }

        return response()->json([
            'ok' => true,
            'accepted' => count($created),
            'items' => $created,
        ]);
    }

    /**
     * @param  callable(): string  $callback
     * @return array<string, mixed>
     */
    protected function toolResult(
        Conversation $conversation,
        string $operation,
        ?string $toolCallId,
        callable $callback,
    ): array {
        $execute = function () use ($callback): array {
            $decoded = json_decode($callback(), true);

            return is_array($decoded) ? $decoded : ['accepted' => 0];
        };

        if (blank($toolCallId)) {
            return $execute();
        }

        $key = 'ai-callback:'.$operation.':'.$conversation->id.':'.sha1($toolCallId);

        return Cache::remember($key, now()->addDay(), $execute);
    }

    /** @return array{0: string, 1: string} */
    protected function memoryFact(string $fact, string $caseId): array
    {
        $fact = trim($fact);

        if (preg_match('/^(?:matter=(\S+)\s+)?type=(\S+)\s+content:\s*(.+)$/s', $fact, $matches) === 1) {
            if (($matches[1] ?? '') !== '' && $matches[1] !== $caseId) {
                return ['', ''];
            }

            return [$matches[2], trim($matches[3])];
        }

        if (preg_match('/^\[([a-z_]+)\]\s*(.+)$/s', $fact, $matches) === 1) {
            return [$matches[1], trim($matches[2])];
        }

        return ['fact', $fact];
    }
}
