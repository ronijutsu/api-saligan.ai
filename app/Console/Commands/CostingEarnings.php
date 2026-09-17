<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\Billing\EarningsModel;
use App\Support\PlanFeatures;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CostingEarnings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'costing:earnings
        {--exchange-rate= : PHP per USD (defaults to billing.fx_usd_php, the rate the app budgets with)}
        {--provider=claude-sonnet-5 : Model costed for plans carrying the frontier_model feature}
        {--base-provider=claude-haiku-4-5 : Model costed for plans without it}
        {--cache-hit-rate=0.7 : Share of requests landing on a warm prompt cache (0-1)}
        {--no-cache : Cost without prompt caching at all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Print per-plan monthly earnings, AI costs, and margins in pesos';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // The rate the app actually budgets with, so this command and the
        // allowance the customer sees cannot be converted at two different
        // numbers. Overriding is still possible, but it is now deliberate.
        $rate = $this->option('exchange-rate') !== null
            ? (float) $this->option('exchange-rate')
            : (float) config('billing.fx_usd_php', 65.0);

        $frontier = (string) $this->option('provider');
        $base = (string) $this->option('base-provider');
        $cached = ! $this->option('no-cache');
        $hitRate = (float) $this->option('cache-hit-rate');

        $this->line(sprintf(
            '%s · %s · ₱%.2f/USD · %s in / %s out per message',
            $this->describeProvider($frontier, 'frontier').'; '.$this->describeProvider($base, 'base'),
            $cached ? sprintf('cache on, %.0f%% hit rate', $hitRate * 100) : 'no caching',
            $rate,
            number_format(EarningsModel::INPUT_TOKENS_PER_MESSAGE),
            number_format(EarningsModel::OUTPUT_TOKENS_PER_MESSAGE),
        ));

        // Paid tiers no longer have a message cap — the allowance is a spend
        // budget — so costing them against a message count reported a zero cap
        // and therefore ₱0.00 of AI COGS. The allowance is translated back into
        // the messages it buys at the plan's own rate, which is the honest
        // worst case: it assumes the customer spends everything they paid for.
        $this->line(sprintf(
            'AI COGS assumes each plan\'s full monthly allowance is consumed (₱%.2f/USD).',
            $rate,
        ));
        $this->newLine();

        $rows = $this->plans()->map(function (array $plan) use ($rate, $frontier, $base, $cached, $hitRate): array {
            // Each plan is costed against the model it is actually served by
            // and across every seat its price covers. Costing the whole ladder
            // at one model and one seat is what made Standard look unprofitable
            // and Firm look like a licence to print money — neither of which
            // was true of the plan as sold.
            $provider = $plan['frontier'] ? $frontier : $base;
            $seats = max(1, $plan['seats']);

            $messages = $this->messagesFor($plan, $provider, $rate, $cached, $hitRate);

            $earnings = EarningsModel::earnings(
                priceCents: $plan['price'],
                messageCap: $messages,
                documentCap: $plan['documents'],
                providerMix: [$provider => 1.0],
                exchangeRate: $rate,
                cached: $cached,
                cacheHitRate: $hitRate,
            );

            return [
                'plan' => $plan['name'],
                'price' => '₱'.number_format($earnings['price_pesos'], 0),
                'model' => $plan['frontier'] ? 'frontier' : 'base',
                'seats' => $seats,
                'messages' => number_format($messages),
                'cost' => '₱'.number_format($earnings['message_cost_pesos'], 2),
                // Shown beside the marginal cost so an overage rate priced
                // below what the message costs to serve is visible at a glance.
                'overage' => $plan['overage'] === null
                    ? '—'
                    : '₱'.number_format($plan['overage'] / 100, 2),
                'ai_cogs' => '₱'.number_format($earnings['ai_cogs_pesos'], 2),
                'paymongo' => '₱'.number_format($earnings['paymongo_pesos'], 2),
                'net' => '₱'.number_format($earnings['net_pesos'], 2),
                'margin' => number_format($earnings['margin'] * 100, 1).'%',
            ];
        });

        $this->table(
            ['Plan', 'Price', 'Model', 'Seats', 'Msgs', 'Cost ₱/msg', 'Overage ₱/msg', 'AI COGS', 'Fees', 'Net', 'Margin'],
            $rows->all(),
        );

        return self::SUCCESS;
    }

    /**
     * How many messages a plan's allowance actually buys, at the rate that plan
     * is served at.
     *
     * A tier with no AI budget falls back to its legacy message cap, which is
     * per seat and so is multiplied out across the seats the price covers. A
     * tier with a budget uses the budget instead — and that is *not* multiplied
     * by seats, because the allowance is one pooled figure for the whole plan
     * (Firm's team shares a single pool), not an amount granted per seat.
     *
     * @param  array{name: string, price: int, messages: int, documents: ?int, overage: ?int, seats: int, frontier: bool, budget_usd: ?float}  $plan
     */
    protected function messagesFor(array $plan, string $provider, float $rate, bool $cached, float $hitRate): int
    {
        $seats = max(1, $plan['seats']);
        $budgetUsd = $plan['budget_usd'];

        if ($budgetUsd === null || $budgetUsd <= 0 || $rate <= 0) {
            return max(0, $plan['messages']) * $seats;
        }

        $perMessageUsd = EarningsModel::perMessageCostPesos($provider, $rate, $cached, $hitRate) / $rate;

        if ($perMessageUsd <= 0) {
            return max(0, $plan['messages']) * $seats;
        }

        return (int) floor($budgetUsd / $perMessageUsd);
    }

    /**
     * One provider's rates, labelled by the role it plays in the ladder.
     */
    protected function describeProvider(string $provider, string $role): string
    {
        $meta = EarningsModel::provider($provider);

        return sprintf('%s at $%.2f/$%.2f per MTok (%s)', $meta['label'], $meta['input'], $meta['output'], $role);
    }

    /**
     * Active plans from the database, falling back to the default pricing
     * definitions when the database has not been seeded.
     *
     * @return Collection<int, array{name: string, price: int, messages: int, documents: ?int, overage: ?int, seats: int, frontier: bool, budget_usd: ?float}>
     */
    protected function plans(): Collection
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($plans->isNotEmpty()) {
            return $plans->map(fn (Plan $plan): array => [
                'name' => $plan->name,
                'price' => $plan->price,
                'messages' => $plan->limits['messages_used'] ?? 0,
                'documents' => $plan->limits['documents_uploaded'] ?? null,
                'overage' => $plan->overage_price,
                'seats' => $plan->included_seats ?? 1,
                'frontier' => in_array(PlanFeatures::FRONTIER_MODEL, $plan->features ?? [], true),
                'budget_usd' => $plan->ai_budget_usd_cents === null
                    ? null
                    : $plan->ai_budget_usd_cents / 100,
            ]);
        }

        return collect(EarningsModel::defaultPlans())->map(fn (array $plan): array => $plan + [
            'seats' => 1,
            'frontier' => $plan['slug'] !== 'standard',
            'budget_usd' => null,
        ]);
    }
}
