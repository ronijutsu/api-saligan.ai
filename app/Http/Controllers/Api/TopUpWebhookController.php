<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\PaymongoClient;
use App\Services\Billing\TopUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PayMongo webhooks for AI top-up packs (ADR-010).
 *
 * Signature-verified like the subscription and vetting webhooks. The events
 * carry only the gateway's identifiers, so a payment intent is matched back to
 * its pending pack and the allowance is credited on the transition to paid —
 * which is what makes a replayed delivery harmless.
 */
class TopUpWebhookController extends Controller
{
    public function __construct(
        private readonly PaymongoClient $paymongo,
        private readonly TopUpService $topups,
    ) {
        //
    }

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->paymongo->verifyWebhookSignature($request->headers->all(), $raw)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $attributes = $request->json('data.attributes', []);
        $eventType = $attributes['type'] ?? null;
        $eventData = $attributes['data'] ?? [];
        $eventAttributes = $eventData['attributes'] ?? [];

        $intentId = $eventAttributes['payment_intent_id'] ?? null;
        $paymentId = (string) ($eventData['id'] ?? '');

        if ($intentId !== null) {
            match ($eventType) {
                'payment.paid' => $this->topups->markPaid((string) $intentId, $paymentId),
                'payment.failed' => $this->topups->markFailed((string) $intentId),
                default => null,
            };
        }

        // Always 200 for a verified delivery: an unhandled event type is not a
        // failure, and a 4xx would only make the gateway retry it.
        return response()->json(['status' => 'ok'], 200);
    }
}
