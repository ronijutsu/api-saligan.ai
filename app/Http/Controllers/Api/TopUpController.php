<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiTopUp;
use App\Services\Billing\TopUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prepaid AI top-ups (ADR-010).
 *
 * Two customer actions and one read: turn extra usage on and set a ceiling,
 * buy a pack, and see what has been bought.
 */
class TopUpController extends Controller
{
    public function __construct(
        private readonly TopUpService $topups,
    ) {
        //
    }

    /**
     * Whether this account may buy extra usage, on what terms, and how much of
     * its ceiling it has used.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->topups->options($request->user())]);
    }

    /**
     * The customer's switch and spending ceiling on extra usage.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'cap_pesos' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $subscription = $this->topups->updateSettings(
            $request->user(),
            (bool) $validated['enabled'],
            array_key_exists('cap_pesos', $validated)
                ? ($validated['cap_pesos'] === null ? null : (int) $validated['cap_pesos'])
                : null,
        );

        return response()->json([
            'data' => [
                'enabled' => (bool) $subscription->topup_enabled,
                'cap_pesos' => $subscription->topup_cap_pesos,
                'options' => $this->topups->options($request->user()),
            ],
        ]);
    }

    /**
     * Buy packs. Returns the gateway's checkout URL; the allowance is credited
     * by the webhook once the payment is confirmed, never here.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'packs' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $topUp = $this->topups->startPurchase($request->user(), (int) ($validated['packs'] ?? 1));

        return response()->json([
            'data' => [
                'id' => $topUp->id,
                'status' => $topUp->status->value,
                'packs' => $topUp->packs,
                'amount_pesos' => $topUp->amount_pesos,
                'usd' => $topUp->allowanceUsd(),
                'checkout_url' => $topUp->checkout_url,
            ],
        ], 201);
    }

    /**
     * Purchases for the current window, newest first.
     */
    public function history(Request $request): JsonResponse
    {
        $subscription = $this->topups->subscriptionFor($request->user());

        $topUps = AiTopUp::query()
            ->where('subscription_id', $subscription->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (AiTopUp $topUp): array => [
                'id' => $topUp->id,
                'status' => $topUp->status->value,
                'packs' => $topUp->packs,
                'amount_pesos' => $topUp->amount_pesos,
                'usd' => $topUp->allowanceUsd(),
                'paid_at' => $topUp->paid_at?->toIso8601String(),
                'created_at' => $topUp->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $topUps]);
    }
}
