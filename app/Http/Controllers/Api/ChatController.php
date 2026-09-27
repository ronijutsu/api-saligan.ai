<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\PythonAiClient;
use App\Services\Billing\AiBudget;
use App\Support\ChatFrames;
use App\Support\DraftingIntent;
use Closure;
use Generator;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Octane\Contracts\Client as OctaneClient;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    public function __construct(
        private readonly PythonAiClient $pythonAi,
    ) {
        //
    }

    /**
     * Answer a message in a conversation, streaming the response as SSE.
     */
    public function store(Request $request, Conversation $conversation): StreamedResponse|JsonResponse
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'request_id' => ['sometimes', 'uuid'],
            'attachment_ids' => ['array', 'max:10'],
            'attachment_ids.*' => ['uuid'],
        ]);

        $requestId = $validated['request_id'] ?? null;
        $requestLock = null;

        if ($requestId !== null) {
            // A completed retry can be answered from the durable row without
            // buying another model call. The id is scoped to this conversation
            // so a caller cannot use it to discover another thread's message.
            $existing = Message::query()->find($requestId);

            if ($existing?->conversation_id === $conversation->id && $existing->role->value === 'assistant') {
                $frames = (function () use ($requestId): Generator {
                    yield $this->sseFrame('done', [
                        'ok' => true,
                        'web_citations' => 0,
                        'message_id' => $requestId,
                    ]);
                })();

                return response()->stream($this->streamEmitter($frames), 200, [
                    'Content-Type' => 'text/event-stream',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'X-Accel-Buffering' => 'no',
                    'Connection' => 'keep-alive',
                ]);
            }

            if ($existing !== null) {
                return response()->json([
                    'message' => 'The request id is already used by another message.',
                ], 409);
            }

            $requestLock = Cache::lock("chat.turn.{$conversation->id}.{$requestId}", 900);

            if (! $requestLock->get()) {
                return response()->json([
                    'message' => 'This message is already being processed.',
                    'request_id' => $requestId,
                ], 409);
            }
        }

        // One spend reservation covers the whole turn: the
        // answering call plus whatever helpers it fans out to. Helpers never
        // reserve on their own, so a turn with three searches costs one hold
        // and settles one measured total.
        try {
            $reservation = AiBudget::reserve(
                $request->user(),
                AiUsage::OPERATION_CHAT,
                context: ['conversation_id' => $conversation->id],
            );
        } catch (Throwable $exception) {
            $requestLock?->release();

            throw $exception;
        }

        try {
            $message = $validated['message'];

            $attachmentIds = $this->ownedAttachmentIds($request, $validated['attachment_ids'] ?? []);

            $isDraftingRequest = DraftingIntent::matches($message);
            $isIntakeSubmission = DraftingIntent::isIntakeSubmission($message);

            $upstream = $this->pythonAi->streamChat(
                $conversation->id,
                $message,
                $attachmentIds,
                $isDraftingRequest,
                $isIntakeSubmission,
                $reservation->id,
                $requestId,
            );
            $frames = $this->pythonAi->body($upstream);

            if ($requestLock !== null) {
                $frames = $this->releaseRequestLock($frames, $requestLock);
            }

            return response()->stream($this->streamEmitter($frames), 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]);
        } catch (Throwable $exception) {
            // Setup failures happen before the response body is consumed, so
            // no provider callback can have settled the hold yet. Release
            // both resources and keep retries from being blocked by a stale
            // fifteen-minute lock. release() is idempotent if the provider
            // path already handled the same failure.
            AiBudget::release($reservation);
            $requestLock?->release();

            throw $exception;
        }
    }

    /**
     * Hold a duplicate-send lock for the complete upstream stream, not merely
     * for the time it takes to construct the response. A second browser POST
     * therefore cannot start a second provider turn while the first is still
     * generating.
     *
     * @param  Generator<int, string>  $frames
     * @return Generator<int, string>
     */
    protected function releaseRequestLock(Generator $frames, Lock $lock): Generator
    {
        try {
            yield from $frames;
        } finally {
            $lock->release();
        }
    }

    /**
     * Keep only the attachment ids that belong to the requesting user, in the
     * order they were sent. Anything else is dropped rather than rejected: a
     * document deleted between upload and send should not fail the message.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    protected function ownedAttachmentIds(Request $request, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $owned = Document::query()
            ->whereIn('id', $ids)
            ->where('user_id', $request->user()->id)
            ->pluck('id')
            ->all();

        return array_values(array_intersect($ids, $owned));
    }

    /**
     * Emit the given SSE frames to the client.
     *
     * RoadRunner streams incrementally only when the StreamedResponse callback
     * returns a Generator (it ignores echo()/ob_flush()/flush()), so when the
     * request is served by Octane the callback yields each frame. Outside
     * Octane (tests, PHP-FPM) the same frames are echoed and flushed so the
     * response body is still produced.
     *
     * @param  Generator<int, string>  $frames
     * @return Closure(): Generator<int, string>|Closure(): void
     */
    protected function streamEmitter(Generator $frames): Closure
    {
        if (app()->bound(OctaneClient::class)) {
            return function () use ($frames): Generator {
                yield from $frames;
            };
        }

        return function () use ($frames): void {
            @ini_set('output_buffering', '0');
            @ini_set('zlib.output_compression', '0');

            foreach ($frames as $frame) {
                echo $frame;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        };
    }

    /**
     * Build a single SSE frame string.
     *
     * @param  array<string, mixed>  $data
     */
    protected function sseFrame(string $event, array $data): string
    {
        return ChatFrames::frame($event, $data);
    }
}
