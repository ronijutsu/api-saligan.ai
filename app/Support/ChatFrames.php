<?php

namespace App\Support;

/**
 * Encodes the SSE frames Laravel itself writes on a chat stream — today only
 * the replayed `done` for a request id that already completed. Every other
 * frame is relayed from ai-provider, which decides what a turn may put on the
 * wire; the shared `contracts/sse/corpus.json` keeps the two encoders equal.
 */
final class ChatFrames
{
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
}
