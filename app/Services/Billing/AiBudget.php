<?php

namespace App\Services\Billing;

use App\Models\AiUsage;
use App\Models\Subscription;
use App\Models\SubscriptionWindow;
use App\Models\User;
use App\Notifications\UsageBudgetWarning;
use App\Support\PlanLimits;
use App\Support\UpgradeResponse;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The monthly AI spend allowance, enforced in dollars and shown in percent.
 *
 * Every AI operation reserves an estimate against the subscription's
 * anniversary window before the provider is called, then settles with the
 * measured cost after. The customer never sees tokens, models, or tool calls
 * — one meter, one reset date, warnings at 80%, a hard stop at 100%.
 *
 * Failures settle nothing: a reservation for work that never ran is released,
 * so provider outages and empty replies cannot eat the allowance. Internal
 * retries inside one operation share its single reservation, so they never
 * multiply the charge either.
 */
final class AiBudget
{
    /**
     * Reserve spend for one AI operation.
     *
     * Aborts 402 when the window cannot cover even the estimate. Returns the
     * open reservation; the caller settles it once the provider answers and
     * releases it when nothing billable ran.
     *
     * @param  array<string, mixed>  $context  conversation_id, document_id,
     *                                         engine for the ledger row.
     */
    public static function reserve(
        User $user,
        string $operation,
        ?float $estimateUsd = null,
        ?string $idempotencyKey = null,
        array $context = [],
    ): AiUsage {
        PlanLimits::ensureActiveAccess($user);

        if ($user->is_admin) {
            return self::untrackedReservation($user, $operation, $context);
        }

        $subscription = $user->subscription;

        if ($subscription === null) {
            abort(UpgradeResponse::make('Subscribe to a plan to access Saligan.ai.'));
        }

        return DB::transaction(function () use ($user, $subscription, $operation, $estimateUsd, $idempotencyKey, $context): AiUsage {
            if ($idempotencyKey !== null) {
                $existing = AiUsage::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $window = self::lockWindow($subscription);
            self::pruneExpiredReservations($window);

            // Legacy counters keep counting alongside the ledger: trial
            // lifecycle, warnings, and existing meters still read them, while
            // the spend budget is the gate that binds first on paid tiers
            // (whose count caps are null). Consume first so a capped trial
            // still ends the trial through its own path below.
            self::legacyConsume($user, $operation);

            $estimate = $estimateUsd ?? AiCosting::estimateFor($operation);
            $open = self::openReservedUsd($window);

            // The ceiling is the plan's allowance plus anything bought for this
            // window (ADR-010). Top-ups expire with the window they were paid
            // for, so this stays a single comparison against one number.
            $budget = $window->effectiveBudgetUsd();

            if ($budget > 0 && $window->used_usd + $open + $estimate > $budget) {
                // The message was already counted above; hand it back before
                // refusing, or an exhausted budget would also eat the counts.
                self::legacyRefund($user, $operation);

                abort(self::exhaustedResponse($subscription, $window));
            }

            return AiUsage::create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'subscription_window_id' => $window->id,
                'conversation_id' => $context['conversation_id'] ?? null,
                'document_id' => $context['document_id'] ?? null,
                'operation' => $operation,
                // Every AI operation runs in ai-provider.
                'engine' => $context['engine'] ?? 'python',
                'status' => AiUsage::STATUS_RESERVED,
                // The hold counts against the budget until settle replaces it
                // with the measured cost, so concurrent turns cannot each
                // spend the last of the allowance.
                'cost_usd' => round($estimate, 6),
                'rate_version' => AiCosting::RATE_VERSION,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /**
     * Settle a reservation with the measured cost of the work.
     *
     * Idempotent: settling twice (the Python callback retried, the stream's
     * completion handler raced the error handler) charges once. Settling past
     * the budget is allowed — the work already ran, and refusing to record it
     * would only corrupt the ledger. The next reservation is what stops.
     *
     * @param  array<string, mixed>  $actuals  provider, model, input_tokens,
     *                                         output_tokens, cache_read_tokens,
     *                                         cache_write_tokens, image_pages,
     *                                         grounding_queries, attempts,
     *                                         latency_ms, cost_usd (explicit
     *                                         override; computed when absent).
     */
    public static function settle(AiUsage $usage, array $actuals = []): AiUsage
    {
        return DB::transaction(function () use ($usage, $actuals): AiUsage {
            $usage = AiUsage::query()->whereKey($usage->id)->lockForUpdate()->firstOrFail();

            if ($usage->status === AiUsage::STATUS_SETTLED) {
                return $usage;
            }

            $cost = $actuals['cost_usd'] ?? AiCosting::callCostUsd(
                $actuals['provider'] ?? $usage->provider,
                $actuals['model'] ?? $usage->model,
                (int) ($actuals['input_tokens'] ?? 0),
                (int) ($actuals['output_tokens'] ?? 0),
                (int) ($actuals['cache_read_tokens'] ?? 0),
                (int) ($actuals['cache_write_tokens'] ?? 0),
            );
            $cost += ((int) ($actuals['grounding_queries'] ?? 0)) * AiCosting::GROUNDING_USD_PER_QUERY;
            $cost = round(max(0.0, (float) $cost), 6);

            $usage->forceFill([
                'provider' => $actuals['provider'] ?? $usage->provider,
                'model' => $actuals['model'] ?? $usage->model,
                'input_tokens' => (int) ($actuals['input_tokens'] ?? 0),
                'output_tokens' => (int) ($actuals['output_tokens'] ?? 0),
                'cache_read_tokens' => (int) ($actuals['cache_read_tokens'] ?? 0),
                'cache_write_tokens' => (int) ($actuals['cache_write_tokens'] ?? 0),
                'image_pages' => (int) ($actuals['image_pages'] ?? 0),
                'grounding_queries' => (int) ($actuals['grounding_queries'] ?? 0),
                'attempts' => max(1, (int) ($actuals['attempts'] ?? $usage->attempts)),
                'status' => AiUsage::STATUS_SETTLED,
                'cost_usd' => $cost,
                'rate_version' => AiCosting::RATE_VERSION,
                'latency_ms' => $actuals['latency_ms'] ?? $usage->latency_ms,
            ])->save();

            $window = $usage->window()->lockForUpdate()->first();

            if ($window !== null) {
                // The meter is settled actuals plus open holds (summed where
                // read): the hold leaves the open sum the moment this row
                // settles, and the measured cost lands here. Settling under
                // the estimate therefore hands spend back by construction,
                // and the meter can never inherit the hold's sign.
                $window->increment('used_usd', $cost);
                $window->refresh();
                self::warnIfRunningLow($usage->subscription, $window);
                self::endTrialIfExhausted($usage->subscription, $window);
            }

            return $usage->refresh();
        });
    }

    /**
     * Settle a reservation straight from a persisted usage summary — the shape
     * both chat engines store on the reply — plus any explicit overrides.
     *
     * @param  array<string, mixed>  $summary
     */
    public static function settleTurn(AiUsage $usage, array $summary, array $overrides = []): AiUsage
    {
        return self::settle($usage, [
            'provider' => $overrides['provider'] ?? $summary['provider'] ?? null,
            'model' => $overrides['model'] ?? $summary['model'] ?? null,
            'input_tokens' => $summary['input_tokens'] ?? 0,
            'output_tokens' => $summary['output_tokens'] ?? 0,
            'cache_read_tokens' => $summary['cache_read_tokens'] ?? 0,
            'cache_write_tokens' => $summary['cache_write_tokens'] ?? 0,
            'grounding_queries' => $overrides['grounding_queries'] ?? count($summary['search_calls'] ?? []),
            'cost_usd' => $overrides['cost_usd'] ?? AiCosting::turnCostUsd($summary, $overrides['provider'] ?? null, $overrides['model'] ?? null),
            'attempts' => $overrides['attempts'] ?? 1,
            'latency_ms' => $overrides['latency_ms'] ?? null,
        ]);
    }

    /**
     * Release a reservation for work that never ran anything billable: the
     * provider never started, the turn was discarded, ingestion failed.
     * Safe to call on rows already settled or released — only open
     * reservations move.
     */
    public static function release(AiUsage $usage, bool $failed = false): AiUsage
    {
        return DB::transaction(function () use ($usage, $failed): AiUsage {
            $usage = AiUsage::query()->whereKey($usage->id)->lockForUpdate()->firstOrFail();

            if ($usage->status !== AiUsage::STATUS_RESERVED) {
                return $usage;
            }

            $usage->forceFill([
                'status' => $failed ? AiUsage::STATUS_FAILED : AiUsage::STATUS_RELEASED,
                'cost_usd' => 0,
            ])->save();

            if ($usage->user !== null) {
                self::legacyRefund($usage->user, $usage->operation);
            }

            return $usage;
        });
    }

    /**
     * Which legacy counter an operation counted against, before the spend
     * budget became the gate. Chat turns and ingests kept their counters for
     * trial lifecycle and observability; helpers were never counted and stay
     * that way.
     */
    protected static function legacyCounterFor(string $operation): ?string
    {
        return match ($operation) {
            AiUsage::OPERATION_CHAT => PlanLimits::MESSAGE_KEY,
            AiUsage::OPERATION_INGEST => 'documents_uploaded',
            default => null,
        };
    }

    protected static function legacyConsume(User $user, string $operation): void
    {
        if ($operation === AiUsage::OPERATION_CHAT) {
            PlanLimits::consumeMessage($user);

            return;
        }

        if ($operation === AiUsage::OPERATION_INGEST) {
            PlanLimits::ensureCanUse($user, 'documents_uploaded');
            PlanLimits::increment($user, 'documents_uploaded');
        }
    }

    protected static function legacyRefund(User $user, string $operation): void
    {
        $key = self::legacyCounterFor($operation);

        if ($key === null) {
            return;
        }

        if ($key === PlanLimits::MESSAGE_KEY) {
            PlanLimits::refundMessage($user);

            return;
        }

        $counter = $user->usageCounterForCurrentPeriod();

        if (($counter->{$key} ?? 0) > 0) {
            $counter->decrement($key);
        }
    }

    /**
     * The subscription's current anniversary window, created on demand.
     *
     * Monthly plans spend one window per billing period; annual plans slice
     * the year into twelve monthly windows from the subscription's start, so
     * a yearly buyer gets a monthly allowance twelve times rather than one
     * annual pool. Trials spend one window from grant to expiry.
     */
    public static function windowFor(Subscription $subscription): SubscriptionWindow
    {
        return DB::transaction(function () use ($subscription): SubscriptionWindow {
            return self::lockWindow($subscription->fresh() ?? $subscription);
        });
    }

    /**
     * Snapshot for the customer meter: percent spent, warning state, and the
     * reset date the UI renders. Uncapped plans report a zero budget and a
     * zero percent — never nulls the frontend has to defend against.
     *
     * @return array{used_usd: float, budget_usd: float, percent: float, warning: bool, exhausted: bool, warned_80_at: ?string, window_start: ?string, window_end: ?string}
     */
    public static function snapshot(User $user): array
    {
        $subscription = $user->subscription;

        if ($subscription === null) {
            return self::emptySnapshot();
        }

        $window = self::windowFor($subscription);
        self::pruneExpiredReservations($window);

        // Open holds count: a turn in flight has spent its estimate as far as
        // the next turn is concerned.
        $used = (float) $window->used_usd + self::openReservedUsd($window);

        // The meter reads the effective allowance, not the plan's alone: a
        // customer who bought extra usage must see the meter they are actually
        // being gated on, or the number and the refusal disagree (ADR-010).
        $budget = $window->effectiveBudgetUsd();

        $percent = $budget > 0
            ? min(999.0, round($used / $budget * 100, 1))
            : 0.0;

        return [
            'used_usd' => round($used, 4),
            'budget_usd' => round($budget, 4),
            'topup_usd' => round((float) $window->topup_usd, 4),
            'percent' => $percent,
            'warning' => $window->isWarning(),
            'exhausted' => $window->isExhausted(),
            'warned_80_at' => $window->warned_80_at?->toIso8601String(),
            'window_start' => $window->window_start?->toIso8601String(),
            'window_end' => $window->window_end?->toIso8601String(),
        ];
    }

    /**
     * The subscription API's customer-facing meter. Storage and provider
     * accounting remain in USD internally; clients receive PHP only.
     *
     * @return array{used_pesos: float, budget_pesos: float, percent: float, warning: bool, exhausted: bool, warned_80_at: ?string, window_start: ?string, window_end: ?string}
     */
    public static function customerSnapshot(User $user): array
    {
        $snapshot = self::snapshot($user);

        return [
            'used_pesos' => round(AiCosting::toPesos($snapshot['used_usd']), 2),
            'budget_pesos' => round(AiCosting::toPesos($snapshot['budget_usd']), 2),
            'percent' => $snapshot['percent'],
            'warning' => $snapshot['warning'],
            'exhausted' => $snapshot['exhausted'],
            'warned_80_at' => $snapshot['warned_80_at'],
            'window_start' => $snapshot['window_start'],
            'window_end' => $snapshot['window_end'],
        ];
    }

    /**
     * @return array{used_pesos: float, budget_pesos: float, percent: float, warning: bool, exhausted: bool, warned_80_at: ?string, window_start: ?string, window_end: ?string}
     */
    public static function emptyCustomerSnapshot(): array
    {
        return [
            'used_pesos' => 0.0,
            'budget_pesos' => 0.0,
            'percent' => 0.0,
            'warning' => false,
            'exhausted' => false,
            'warned_80_at' => null,
            'window_start' => null,
            'window_end' => null,
        ];
    }

    /**
     * @return array{used_usd: float, budget_usd: float, percent: float, warning: bool, exhausted: bool, warned_80_at: ?string, window_start: ?string, window_end: ?string}
     */
    public static function emptySnapshot(): array
    {
        return [
            'used_usd' => 0.0,
            'budget_usd' => 0.0,
            'percent' => 0.0,
            'warning' => false,
            'exhausted' => false,
            'warned_80_at' => null,
            'window_start' => null,
            'window_end' => null,
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public static function windowBounds(Subscription $subscription): array
    {
        $now = now();

        if ($subscription->onTrial() && $subscription->trial_ends_at !== null) {
            $start = $subscription->created_at ?? $now->copy()->subDays(14);

            return [$start->copy(), $subscription->trial_ends_at->copy()];
        }

        $periodStart = $subscription->current_period_start
            ? $subscription->current_period_start->copy()->startOfDay()
            : $now->copy()->startOfMonth();
        $periodEnd = $subscription->current_period_end
            ? $subscription->current_period_end->copy()->endOfDay()
            : $periodStart->copy()->addMonth();

        if (($subscription->interval ?? 'monthly') !== 'annual') {
            return [$periodStart, $periodEnd];
        }

        // Annual buyers get twelve monthly slices from their start date, each
        // capped at the period end so the final window never overshoots it.
        $elapsed = max(0, $periodStart->diffInMonths($now, false));
        $start = $periodStart->copy()->addMonths($elapsed);
        $candidate = $start->copy()->addMonth();

        return [$start, $candidate->lessThan($periodEnd) ? $candidate : $periodEnd];
    }

    protected static function lockWindow(Subscription $subscription): SubscriptionWindow
    {
        [$start, $end] = self::windowBounds($subscription);
        $budget = self::budgetUsd($subscription);

        $window = SubscriptionWindow::query()->where('subscription_id', $subscription->id)
            ->where('window_start', $start->toDateTimeString())
            ->lockForUpdate()
            ->first();

        if ($window === null) {
            return SubscriptionWindow::create([
                'subscription_id' => $subscription->id,
                'window_start' => $start,
                'window_end' => $end,
                'budget_usd' => $budget,
                'used_usd' => 0,
            ]);
        }

        // A mid-window plan change takes effect immediately: the ceiling moves
        // to the new plan while the spend stays, so upgrades unblock and
        // downgrades cannot strand a larger allowance than the plan carries.
        if (abs($window->budget_usd - $budget) > 0.0000005) {
            $window->forceFill(['budget_usd' => $budget, 'window_end' => $end])->save();
        }

        return $window->refresh();
    }

    protected static function budgetUsd(Subscription $subscription): float
    {
        $budgetUsd = $subscription->plan?->aiBudgetUsd();

        if ($budgetUsd === null) {
            return 0.0;
        }

        return max(0, $budgetUsd);
    }

    protected static function openReservedUsd(SubscriptionWindow $window): float
    {
        return (float) AiUsage::query()
            ->where('subscription_window_id', $window->id)
            ->where('status', AiUsage::STATUS_RESERVED)
            ->sum('cost_usd');
    }

    /**
     * Reservations are holds, not spend: one whose worker crashed must stop
     * counting once its minutes are up, or it holds budget hostage until the
     * window rolls.
     */
    protected static function pruneExpiredReservations(SubscriptionWindow $window): void
    {
        $ttl = max(1, (int) config('billing.reservation_ttl_minutes', 15));

        // Per row rather than bulk: a crashed worker's hold must also hand
        // back the legacy count it consumed, or the counters drift from the
        // ledger one crash at a time.
        AiUsage::query()
            ->with('user')
            ->where('subscription_window_id', $window->id)
            ->where('status', AiUsage::STATUS_RESERVED)
            ->where('updated_at', '<', now()->subMinutes($ttl))
            ->chunkById(100, function ($usages): void {
                foreach ($usages as $usage) {
                    $usage->forceFill(['status' => AiUsage::STATUS_RELEASED, 'cost_usd' => 0])->save();

                    if ($usage->user !== null) {
                        self::legacyRefund($usage->user, $usage->operation);
                    }
                }
            });
    }

    /**
     * A reservation with no subscription behind it — admins and support
     * impersonation. Tracked for the ledger, never gated, never settled.
     */
    protected static function untrackedReservation(User $user, string $operation, array $context): AiUsage
    {
        self::legacyConsume($user, $operation);

        return AiUsage::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'subscription_id' => $user->subscription?->id,
            'conversation_id' => $context['conversation_id'] ?? null,
            'document_id' => $context['document_id'] ?? null,
            'operation' => $operation,
            'engine' => $context['engine'] ?? null,
            'status' => AiUsage::STATUS_RESERVED,
            'idempotency_key' => $context['idempotency_key'] ?? 'admin:'.Str::uuid(),
        ]);
    }

    protected static function exhaustedResponse(Subscription $subscription, SubscriptionWindow $window)
    {
        $reset = $window->window_end?->format('F j');

        // Percent counts open holds too: the refusal is about spend plus what
        // is already in flight, and the meter must agree with the refusal.
        $budget = $window->effectiveBudgetUsd();

        $percent = $budget > 0
            ? min(999.0, round(((float) $window->used_usd + self::openReservedUsd($window)) / $budget * 100, 1))
            : 100.0;

        // An account that may buy extra usage gets an actionable refusal rather
        // than the dead end of "upgrade or wait" — it is already on the tier it
        // would be told to upgrade to (ADR-010).
        $canTopUp = $subscription->topup_enabled
            && $subscription->plan?->canBuyTopUps() === true;

        return UpgradeResponse::make(
            $canTopUp
                ? ($reset
                    ? "You've used this month's AI allowance. Add extra usage to keep working, or wait for the {$reset} reset."
                    : "You've used this month's AI allowance. Add extra usage to keep working.")
                : ($reset
                    ? "You've used this month's AI allowance. It resets on {$reset} — upgrade for a larger allowance, or continue then."
                    : "You've used this month's AI allowance. Upgrade for a larger allowance, or continue when it resets."),
            [
                'usage_percent' => $percent,
                'usage_reset_at' => $window->window_end?->toIso8601String(),
                'can_top_up' => $canTopUp,
            ],
        );
    }

    protected static function warnIfRunningLow(?Subscription $subscription, SubscriptionWindow $window): void
    {
        $threshold = (float) config('billing.usage_warn_percent', 80.0);

        if ($subscription === null || $window->warned_80_at !== null || $threshold <= 0) {
            return;
        }

        if ($window->percentUsed() < $threshold || $window->isExhausted()) {
            return;
        }

        $window->forceFill(['warned_80_at' => now()])->save();

        $owner = $subscription->user
            ?? User::query()
                ->where('organization_id', $subscription->organization_id)
                ->where('org_status', User::ORG_STATUS_ACTIVE)
                ->whereIn('org_role', [User::ORG_ROLE_OWNER, User::ORG_ROLE_ADMIN])
                ->first();

        if ($owner === null) {
            return;
        }

        try {
            $owner->notify(new UsageBudgetWarning($subscription, $window));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * A spent trial is over the same way a fully-messaged one was: the row
     * stays so history survives, but access ends now rather than at some
     * sweep's convenience.
     */
    protected static function endTrialIfExhausted(?Subscription $subscription, SubscriptionWindow $window): void
    {
        if ($subscription === null || ! $subscription->onTrial() || ! $window->isExhausted()) {
            return;
        }

        $subscription->forceFill([
            'trial_ends_at' => now(),
            'current_period_end' => now(),
        ])->save();
    }
}
