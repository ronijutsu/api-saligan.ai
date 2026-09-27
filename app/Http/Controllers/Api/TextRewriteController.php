<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Message;
use App\Services\Billing\AiBudget;
use App\Services\Billing\AiCosting;
use App\Services\TextRewrite\TextRewriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Rewrites a selected passage of a letter through the AI provider. The result
 * is plain text that the editor offers back as a suggestion, so the letter
 * itself is only ever edited on the client.
 *
 * The call is scoped to the message the letter hangs off when the editor sends
 * one, which is what lets the rewrite see the matter — the party names, the
 * dates already established, the register the correspondence uses. Without it
 * the rewriter has no idea which case it is working on.
 */
class TextRewriteController extends Controller
{
    public function __construct(
        protected TextRewriteService $service,
    ) {
        //
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:8000'],
            'instruction' => ['required', 'string', 'max:200'],
            // The assistant message carrying this letter draft. Optional: a
            // letter opened outside a thread still rewrites, just without the
            // matter's context.
            'message_id' => ['nullable', 'uuid'],
        ]);

        $conversation = $this->conversationFor($request, $validated['message_id'] ?? null);

        // Rewrites were the largest unmetered AI surface: every suggestion
        // reserves spend up front like a chat turn, and empty replies release
        // it rather than billing for nothing.
        $reservation = AiBudget::reserve($request->user(), AiUsage::OPERATION_REWRITE, context: [
            'conversation_id' => $conversation?->id,
        ]);

        try {
            $rewritten = $this->service->rewrite(
                text: $validated['text'],
                instruction: $validated['instruction'],
                conversation: $conversation,
            );
        } catch (Throwable $exception) {
            AiBudget::release($reservation, failed: true);

            throw $exception;
        }

        if ($rewritten === null) {
            AiBudget::release($reservation);

            // Reported rather than silently answered with the original text: a
            // suggestion identical to what is already on screen is
            // indistinguishable, to the reader, from a rewrite that decided no
            // change was needed.
            return response()->json([
                'message' => 'The assistant could not rewrite that passage. Please try again.',
            ], 422);
        }

        // Measured tokens when the engine reported them, the pre-agreed
        // estimate otherwise — never a surprise either way.
        AiBudget::settle($reservation, $this->service->lastUsage ?? [
            'cost_usd' => AiCosting::estimateFor(AiUsage::OPERATION_REWRITE),
        ]);

        return response()->json(['data' => ['text' => $rewritten]]);
    }

    /**
     * The thread the passage belongs to, when the caller may read it.
     *
     * A message id the user cannot access yields no context rather than a 403:
     * the rewrite is about text they already have in front of them, so
     * refusing it outright would block a legitimate edit over a stale id.
     */
    protected function conversationFor(Request $request, ?string $messageId)
    {
        if ($messageId === null) {
            return null;
        }

        $conversation = Message::query()->whereKey($messageId)->first()?->conversation;

        return $conversation?->isAccessibleBy($request->user()) === true ? $conversation : null;
    }
}
