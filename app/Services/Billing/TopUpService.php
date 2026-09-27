<?php

namespace App\Services\Billing;

use App\Enums\TopUpStatus;
use App\Models\AiTopUp;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionWindow;
use App\Models\User;
use App\Support\UpgradeResponse;
use Illuminate\Support\Facades\DB;

/**
 * Prepaid AI top-ups (ADR-010).
 *
 * A pack is charged before its allowance exists, so the account can never owe
 * money it has already spent — that was the reason post-pay overage was removed
 * in the first place. The pack's allowance is written onto the window it was
 * bought for and expires with that window.
 */
class TopUpService
{
    public function __construct(
        private readonly PaymongoClient $paymongo,
    ) {
        //
    }

    /**
     * Everything the billing screen needs to render the control: whether the
     * tier may buy extra usage, what a pack costs and grants, what the customer
     * has already spent on packs this window, and their ceiling.
     *
     * @return array<string, mixed>
     */
    public function options(User $user): array
    {
        $subscription = $user->subscription;

        if ($subscription === null) {
            return ['available' => false, 'reason' => 'no_subscription'];
        }

        $plan = $subscription->plan;
        $window = AiBudget::windowFor($subscription);

        $allowed = $plan !== null && $plan->canBuyTopUps();
        $spent = $window === null ? 0 : $this->spentPesos($window);

        return [
            'available' => $allowed && $subscription->topup_enabled,
            'plan_allows' => $allowed,
            'enabled' => (bool) $subscription->topup_enabled,
            'reason' => $allowed ? null : 'plan_not_eligible',
            'pack' => $plan === null || ! $allowed ? null : [
                'price_pesos' => $plan->topup_price,
                'price_label' => $this->pesoLabel((int) $plan->topup_price),
                'usd' => round($plan->topup_usd_cents / 100, 2),
                'max_per_window' => $plan->topup_max_per_window,
            ],
            'cap_pesos' => $subscription->topup_cap_pesos,
            'spent_pesos' => $spent,
            'remaining_pesos' => $subscription->topup_cap_pesos === null
                ? null
                : max(0, $subscription->topup_cap_pesos - $spent),
            'window_end' => $window?->window_end?->toIso8601String(),
        ];
    }

    /**
     * The customer's own switch and spending ceiling.
     */
    public function updateSettings(User $user, bool $enabled, ?int $capPesos): Subscription
    {
        $subscription = $this->subscriptionFor($user);

        if ($enabled && ($subscription->plan?->canBuyTopUps() !== true)) {
            abort(UpgradeResponse::make(
                'Extra AI usage is not available on your current plan.',
                ['topup_unavailable' => true],
            ));
        }

        $subscription->forceFill([
            'topup_enabled' => $enabled,
            // A ceiling of zero would mean "never", which is the switch's job.
            'topup_cap_pesos' => $capPesos !== null && $capPesos > 0 ? $capPesos : null,
        ])->save();

        return $subscription->fresh();
    }

    /**
     * Start a purchase: create the gateway intent and checkout, and record the
     * pack as pending before the customer is sent anywhere.
     *
     * The row exists first on purpose — the webhook that confirms payment has
     * only the gateway's identifiers to match on, so there must already be a
     * row to credit.
     */
    public function startPurchase(User $user, int $packs = 1): AiTopUp
    {
        $subscription = $this->subscriptionFor($user);
        $plan = $subscription->plan;
        $window = AiBudget::windowFor($subscription);

        $this->assertCanPurchase($subscription, $plan, $window, $packs);

        $amount = (int) $plan->topup_price * $packs;
        $usdCents = (int) $plan->topup_usd_cents * $packs;

        return DB::transaction(function () use ($user, $subscription, $window, $packs, $amount, $usdCents): AiTopUp {
            $topUp = AiTopUp::create([
                'subscription_id' => $subscription->id,
                'subscription_window_id' => $window->id,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'packs' => $packs,
                'amount_pesos' => $amount,
                'usd_cents' => $usdCents,
                'status' => TopUpStatus::Pending,
                'gateway' => 'paymongo',
            ]);

            $intent = $this->paymongo->createPaymentIntent(
                amount: $amount,
                description: $this->description($packs),
                metadata: ['ai_topup_id' => (string) $topUp->id],
                // Unlike vetting, nothing is withheld: the customer pays for the
                // allowance outright, so the charge captures at checkout.
                captureType: 'automatic',
            );

            $intentId = (string) data_get($intent, 'id');
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            $checkout = $this->paymongo->createCheckoutSession(
                paymentIntentId: $intentId,
                customerId: $this->resolveCustomerId($user),
                description: $this->description($packs),
                amount: $amount,
                successUrl: "{$frontendUrl}/settings/billing?topup=return",
                cancelUrl: "{$frontendUrl}/settings/billing?topup=cancelled",
                metadata: ['ai_topup_id' => (string) $topUp->id],
            );

            $topUp->forceFill([
                'gateway_payment_intent_id' => $intentId,
                'checkout_url' => (string) data_get($checkout, 'attributes.checkout_url'),
            ])->save();

            return $topUp;
        });
    }

    /**
     * Credit the allowance a pack paid for.
     *
     * Idempotent by state, not by caller: the row is locked and its status
     * rechecked inside the transaction, so a replayed webhook — or two webhooks
     * racing — grants the allowance exactly once.
     */
    public function markPaid(string $intentId, ?string $paymentId = null): ?AiTopUp
    {
        return DB::transaction(function () use ($intentId, $paymentId): ?AiTopUp {
            $topUp = AiTopUp::query()
                ->where('gateway_payment_intent_id', $intentId)
                ->lockForUpdate()
                ->first();

            if ($topUp === null || $topUp->isSettled()) {
                return $topUp;
            }

            $window = SubscriptionWindow::query()->lockForUpdate()->find($topUp->subscription_window_id);

            if ($window === null) {
                return $topUp;
            }

            $window->forceFill([
                'topup_usd' => round((float) $window->topup_usd + $topUp->allowanceUsd(), 4),
            ])->save();

            $topUp->forceFill([
                'status' => TopUpStatus::Paid,
                'gateway_payment_id' => $paymentId,
                'paid_at' => now(),
            ])->save();

            return $topUp;
        });
    }

    public function markFailed(string $intentId): void
    {
        AiTopUp::query()
            ->where('gateway_payment_intent_id', $intentId)
            ->where('status', TopUpStatus::Pending)
            ->update(['status' => TopUpStatus::Failed]);
    }

    /**
     * What the customer has spent on packs in this window, in minor units.
     */
    public function spentPesos(SubscriptionWindow $window): int
    {
        return (int) AiTopUp::query()
            ->where('subscription_window_id', $window->id)
            ->whereIn('status', [TopUpStatus::Paid->value, TopUpStatus::Pending->value])
            ->sum('amount_pesos');
    }

    /**
     * Packs already bought or in flight for this window.
     */
    public function packsInWindow(SubscriptionWindow $window): int
    {
        return (int) AiTopUp::query()
            ->where('subscription_window_id', $window->id)
            ->whereIn('status', [TopUpStatus::Paid->value, TopUpStatus::Pending->value])
            ->sum('packs');
    }

    public function subscriptionFor(User $user): Subscription
    {
        $subscription = $user->subscription;

        if ($subscription === null) {
            abort(UpgradeResponse::make('Subscribe to a plan to add extra AI usage.'));
        }

        return $subscription;
    }

    /**
     * Every reason a purchase can be refused, checked before the gateway is
     * called so nothing is charged for a pack the account cannot hold.
     */
    protected function assertCanPurchase(Subscription $subscription, ?Plan $plan, ?SubscriptionWindow $window, int $packs): void
    {
        if ($plan === null || ! $plan->canBuyTopUps()) {
            abort(UpgradeResponse::make(
                'Extra AI usage is not available on your current plan.',
                ['topup_unavailable' => true],
            ));
        }

        if (! $subscription->topup_enabled) {
            abort(UpgradeResponse::make(
                'Turn on extra AI usage before buying a pack.',
                ['topup_disabled' => true],
            ));
        }

        if ($window === null || $window->window_end === null || $window->window_end->isPast()) {
            abort(UpgradeResponse::make('This billing window has ended. Try again in a moment.'));
        }

        if ($packs < 1) {
            throw new \InvalidArgumentException('A top-up must be at least one pack.');
        }

        $max = $plan->topup_max_per_window;

        if ($max !== null && $this->packsInWindow($window) + $packs > $max) {
            abort(UpgradeResponse::make(
                "This plan allows {$max} extra-usage packs per month.",
                ['topup_limit_reached' => true],
            ));
        }

        $cap = $subscription->topup_cap_pesos;

        if ($cap !== null && $this->spentPesos($window) + ((int) $plan->topup_price * $packs) > $cap) {
            abort(UpgradeResponse::make(
                'That would go over the spending cap you set for extra AI usage.',
                ['topup_cap_reached' => true],
            ));
        }
    }

    /**
     * PayMongo will not open a checkout session without a customer, so one is
     * reused from the subscription when the account already has one, otherwise
     * found by email or created — and remembered, so the lookup happens once.
     */
    protected function resolveCustomerId(User $user): string
    {
        $subscription = $user->subscription;
        $existing = $subscription?->paymongo_customer_id;

        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $found = $this->paymongo->findCustomerByEmail($user->email);
        $customerId = $found !== null
            ? (string) ($found['id'] ?? '')
            : (string) data_get($this->paymongo->createCustomer($user->email, $user->name), 'id');

        if ($subscription !== null && $customerId !== '') {
            $subscription->forceFill(['paymongo_customer_id' => $customerId])->save();
        }

        return $customerId;
    }

    protected function description(int $packs): string
    {
        return $packs === 1
            ? 'Batayan extra AI usage'
            : "Batayan extra AI usage ({$packs} packs)";
    }

    protected function pesoLabel(int $minor): string
    {
        return '₱'.number_format($minor / 100, 2);
    }
}
