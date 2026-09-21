<?php

namespace App\Support;

/**
 * The one place that decides what a chat turn is allowed to say on the wire.
 *
 * The stream used to forward every tool call and every tool result verbatim:
 * the model's raw arguments, the tool's raw JSON return, tool names the UI has
 * no use for. That put the model's internal plumbing — search queries written
 * for a retrieval model, advisory payloads, template field maps — into the
 * browser, where it is visible in devtools, quotable out of context, and
 * shaped by whatever the model happened to emit rather than by anything the
 * client renders.
 *
 * So the wire format is now a closed set. A tool reaches the client only if
 * the client draws something for it, and it reaches the client as the fields
 * that drawing needs, not as whatever the tool returned.
 */
final class ChatFrames
{
    /**
     * Tool calls the UI renders. Everything else surfaces — if at all — as a
     * status line, which is the honest granularity for "something is running".
     *
     * @var array<int, string>
     */
    private const RENDERED_CALLS = [
        'request_intake_form',
        'ask_user_question',
        'draft_letter',
    ];

    /**
     * Tool results the UI reacts to. `draft_letter` is absent deliberately:
     * its document travels on its own `letter_draft` frame, which carries the
     * message id the editor saves against.
     *
     * @var array<int, string>
     */
    private const RENDERED_RESULTS = [
        'create_todo',
        'flag_advisories',
    ];

    /**
     * Build a single SSE frame.
     *
     * @param  array<string, mixed>  $data
     */
    public static function frame(string $event, array $data): string
    {
        return "event: {$event}\n"
            .'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
    }

    public static function rendersCall(string $tool): bool
    {
        return in_array($tool, self::RENDERED_CALLS, true);
    }

    public static function rendersResult(string $tool): bool
    {
        return in_array($tool, self::RENDERED_RESULTS, true);
    }

    /**
     * The payload for a rendered tool call, reduced to what the client draws.
     *
     * `request_intake_form` and `ask_user_question` are built server-side
     * before they get here (the fields are shaped against the template, the
     * questions are normalized), so those arrive already safe and are passed
     * through. `draft_letter` is a pure signal — the editor opens in its
     * drafting state and the arguments, which contain the user's own matter
     * facts, stay on the server.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null Null when the call must not be sent.
     */
    public static function call(string $tool, array $arguments): ?array
    {
        if (! self::rendersCall($tool)) {
            return null;
        }

        return match ($tool) {
            'draft_letter' => ['name' => $tool],
            default => ['name' => $tool, 'arguments' => $arguments],
        };
    }

    /**
     * The payload for a rendered tool result.
     *
     * Both survivors are refresh signals: the client re-fetches todos and
     * advisories from their own endpoints, where they are serialized by the
     * resources that own them. Only the count travels, so the UI can say how
     * many without ever parsing a tool's return value.
     *
     * @return array<string, mixed>|null Null when the result must not be sent.
     */
    public static function result(string $tool, mixed $result): ?array
    {
        if (! self::rendersResult($tool)) {
            return null;
        }

        $decoded = self::decodeResult($result);

        // A tool that reports failure or returns something unreadable must not
        // be shown as a success receipt. Python's `rendered_result` applies the
        // same rule, and the shared SSE corpus pins both to it.
        if ($decoded === null || ($decoded['ok'] ?? null) === false) {
            return null;
        }

        $items = $decoded['items'] ?? null;

        return ['name' => $tool, 'count' => is_array($items) ? count($items) : null];
    }

    /**
     * The decoded tool return, or null when it is not a JSON object at all.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeResult(mixed $result): ?array
    {
        if (! is_string($result)) {
            return null;
        }

        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : null;
    }
}
