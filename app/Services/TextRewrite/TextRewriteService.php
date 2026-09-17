<?php

namespace App\Services\TextRewrite;

use App\Models\Conversation;
use App\Services\Ai\PythonAiClient;
use App\Services\MatterMemory\MatterMemoryService;
use App\Support\CaseContextBlock;
use Illuminate\Support\Str;

/**
 * Rewrites a selected passage of a letter through the AI agent and returns
 * plain text. The model's reply is treated as untrusted: JSON wrappers (the
 * local Ollama models are asked for valid JSON) are unwrapped, and an empty
 * answer triggers a retry rather than shipping nothing back to the editor.
 */
class TextRewriteService
{
    public function __construct(
        private readonly PythonAiClient $python,
    ) {}

    /**
     * Provider-reported token counts for the last successful rewrite, in the
     * ledger's actuals shape — or null when the provider reported none, in
     * which case the caller settles the estimate instead.
     *
     * @var array<string, mixed>|null
     */
    public ?array $lastUsage = null;

    /**
     * Rewrite the passage.
     *
     * Returns null when every attempt comes back with nothing usable. It used
     * to hand the original text back instead, which reaches the editor as a
     * suggestion identical to what is already there — indistinguishable, to
     * the reader, from a rewrite that decided no change was needed.
     *
     * @param  Conversation|null  $conversation  The thread this letter belongs
     *                                           to, for the matter's own facts.
     */
    public function rewrite(string $text, string $instruction, ?Conversation $conversation = null): ?string
    {
        $this->lastUsage = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $this->python->call('/agents/rewrite', [
                'text' => $text,
                'instruction' => $instruction,
                'context' => $this->contextFor($conversation),
            ]);
            $rewritten = $this->extractText((string) ($response['text'] ?? ''));

            // Retries share the caller's single reservation: only the
            // successful attempt's usage settles, so empty replies never
            // multiply the charge.
            if ($rewritten !== '') {
                $this->lastUsage = $this->reportedUsage($response['usage'] ?? null, $attempt);

                return $rewritten;
            }
        }

        return null;
    }

    /**
     * The matter this passage belongs to, as reference material.
     *
     * The rewrite used to be entirely stateless, so "fix the grammar of this
     * clause" was answered by a model that had never seen the case — it could
     * not tell a party's name from a typo, and had no way to keep a rewritten
     * sentence consistent with the rest of the matter. The block is reference
     * only: the agent is told in the strongest terms that it may not import a
     * fact from here into the passage.
     */
    protected function contextFor(?Conversation $conversation): string
    {
        if ($conversation === null) {
            return '';
        }

        $case = $conversation->case;
        $blocks = [];

        if ($case !== null) {
            $blocks[] = 'CASE
'.trim(app(CaseContextBlock::class)->for($case));
            $blocks[] = 'MATTER MEMORY
'.trim(app(MatterMemoryService::class)->getMemoryBlock($case));
        }

        // The last few turns, so a rewrite lands in the register the rest of
        // the conversation established. Trimmed hard — this is a rewrite of one
        // passage, not a new answer, and the passage itself is the subject.
        $recent = $conversation->messages()
            ->latest()
            ->limit(6)
            ->get()
            ->reverse()
            ->map(fn ($message): string => Str::limit(
                mb_strtoupper($message->role->value).': '.trim((string) $message->content),
                400,
            ))
            ->filter()
            ->implode("\n");

        if (trim($recent) !== '') {
            $blocks[] = 'RECENT CONVERSATION
'.$recent;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Normalize the engine-reported usage into the ledger's actuals shape.
     * Missing counts settle as zero — local inference genuinely bills no
     * tokens — while the attempt count records how many tries the success
     * took, so retry pressure stays visible even though it is not billed.
     *
     * @return array<string, mixed>|null
     */
    protected function reportedUsage(mixed $reported, int $attempts): ?array
    {
        if (! is_array($reported)) {
            return null;
        }

        return [
            'provider' => $reported['provider'] ?? null,
            'model' => $reported['model'] ?? null,
            'input_tokens' => (int) ($reported['input_tokens'] ?? 0),
            'output_tokens' => (int) ($reported['output_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($reported['cache_read_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($reported['cache_write_tokens'] ?? 0),
            'attempts' => $attempts,
        ];
    }

    /**
     * Pull the actual text out of the model's reply. Ollama is asked for valid
     * JSON, which it may return as a bare JSON string ("...") or an object
     * like {"text": "..."}; cloud providers usually return the plain text
     * directly. Any of those shapes is accepted, and stray wrapper quotes are
     * stripped.
     */
    protected function extractText(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return '';
        }

        $decoded = json_decode($trimmed, true);

        if (is_string($decoded)) {
            return trim($decoded);
        }

        if (is_array($decoded)) {
            if (isset($decoded['text']) && is_string($decoded['text'])) {
                return trim($decoded['text']);
            }

            foreach ($decoded as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            // A JSON structure carrying no usable string is not a rewrite. It
            // used to fall through to the return below, which handed the raw
            // source back — so a model answering `{}` put a literal empty
            // object into the letter, and because that string is non-empty the
            // retry loop accepted it as a successful rewrite.
            return '';
        }

        // A bare JSON scalar that is not a string — `null`, a number, `true` —
        // is the same kind of non-answer.
        if ($decoded !== null && ! is_array($decoded)) {
            return '';
        }

        if ($trimmed === 'null') {
            return '';
        }

        // Plain text, possibly wrapped in quotes or a code fence.
        $trimmed = (string) preg_replace('/^```[a-z]*\s*\n?|\n?```$/i', '', $trimmed);
        $trimmed = trim($trimmed);

        if (strlen($trimmed) >= 2 && $trimmed[0] === '"' && $trimmed[-1] === '"') {
            return trim(substr($trimmed, 1, -1));
        }

        return $trimmed;
    }
}
