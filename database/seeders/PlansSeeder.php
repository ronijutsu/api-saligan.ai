<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\Billing\PaymongoClient;
use App\Support\PlanFeatures;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class PlansSeeder extends Seeder
{
    /**
     * The paid ladder, and the reasoning behind every number on it.
     *
     * Three self-serve tiers, priced the way the
     * solo-and-small-firm legal-AI market prices: one seat, one seat with the
     * best model, then a team bundle — with annual at exactly ten months
     * ("two months free") instead of a near-ten figure nobody can reproduce.
     *
     * Tiers sell a monthly AI spend allowance, not a message count: the
     * customer sees one percent meter while the ledger keeps every token (see
     * AiBudget). Message volume is what the allowance buys, and what a turn
     * costs depends on which model writes it — the base model costs roughly
     * half the frontier one at our measured token sizes (see EarningsModel).
     * Standard buys room on the base model; Pro buys the frontier model,
     * deeper retrieval, and scan reading. Firm buys a shared team pool,
     * includes the former Business services, and is available annually.
     *
     * Every tier is capped, never metered: allowances are prepaid and an
     * exhausted allowance refuses the turn rather than growing the bill, so no
     * invoice can surprise anyone. There is no per-message overage by design.
     *
     * Every plan is sized to hold roughly 40% contribution after AI, fees,
     * and an operating reserve. Verify with `artisan costing:earnings` after
     * changing anything here — it costs each tier at the model that tier is
     * served and across every seat its price covers.
     */
    public function run(): void
    {
        // The customer-facing meter is the AI spend allowance
        // (`ai_budget_usd_cents`); the legacy count caps below stay null on
        // paid tiers so the budget is the single gate. Trials keep small
        // explicit counts because the trial lifecycle (warnings, early end)
        // is still expressed in messages as well as spend.
        $paidLimits = [
            'active_cases' => null,
            'documents_uploaded' => null,
            'messages_used' => null,
        ];

        // What every paid plan carries. Reading the template library is free
        // to everyone; drafting from one, exporting the result, and checking
        // the law against the live web are what a subscription buys.
        $baseFeatures = [
            PlanFeatures::DRAFTING,
            PlanFeatures::EXPORTS,
            PlanFeatures::PDF_DOCUMENTS,
            PlanFeatures::WEB_SEARCH,
            PlanFeatures::CRM,
        ];

        $plans = [
            [
                // Seeded inactive so it never appears in paid pricing or
                // checkout. The registration plan selector requests it
                // explicitly, and only the automatic/code trial paths reach it.
                'slug' => Plan::SLUG_TRIAL,
                'name' => 'Free trial',
                'price' => 0,
                'price_annual' => 0,
                'overage_price' => null,
                // Standard is $5.15; the automatic trial receives 10%, rounded
                // up to the next cent so the promise is never under-delivered.
                'ai_budget_usd_cents' => 52,
                'included_seats' => 1,
                'seat_price' => null,
                'sort_order' => 0,
                'is_active' => false,
                // Enough to run a real matter end to end and see cited
                // The $130 spend cap (₱2 × 65 FX) binds about as early as the
                // message cap on the base model; either one ending the trial is
                // correct.
                'limits' => [
                    'active_cases' => null,
                    'documents_uploaded' => 12,
                    'messages_used' => 60,
                ],
                // Free users can draft and export Word, but PDFs are a paid
                // document capability and are refused at every file boundary.
                'features' => [
                    PlanFeatures::DRAFTING,
                    PlanFeatures::EXPORTS,
                    PlanFeatures::WEB_SEARCH,
                ],
            ],
            [
                'slug' => Plan::SLUG_STANDARD,
                'name' => 'Standard',
                'price' => 99900,
                'price_annual' => 999000,
                // Capped rather than metered, deliberately. Standard is where
                // someone is still working out what they need; a bill that can
                // grow while they do that is the wrong thing to hand them.
                // ₱335/mo of AI spend at the budgeting FX rate.
                'overage_price' => null,
                'ai_budget_usd_cents' => 515,
                'ai_usage_multiplier' => 1,
                // A pack buys roughly a quarter of the included allowance for a
                // little over a quarter of the price: ~1.24x the plan's own ₱/USD
                // rate, so topping up never beats moving up a tier (ADR-010).
                // Provisional — owner sign-off pending.
                'allow_top_ups' => true,
                'topup_price' => 30000,
                'topup_usd_cents' => 125,
                'topup_max_per_window' => 8,
                'included_seats' => 1,
                'seat_price' => null,
                'sort_order' => 1,
                'limits' => $paidLimits,
                'features' => $baseFeatures,
            ],
            [
                'slug' => Plan::SLUG_PRO,
                'name' => 'Pro',
                'price' => 249900,
                'price_annual' => 2499000,
                // Capped like every other tier: an exhausted allowance refuses
                // the turn with an upgrade prompt instead of billing per
                // message, because per-message billing was accrued as displayed
                // debt with no collection path behind it.
                // 3x Standard's AI usage allowance: $15.00/mo at the
                // budgeting FX rate. The frontier model costs roughly twice
                // the base one per turn, so 3x Standard's dollars buys about
                // 1.5x its messages — and holds this tier at a 50%+ margin
                // where 5x left it at 21%.
                'overage_price' => null,
                'ai_budget_usd_cents' => 1500,
                'ai_usage_multiplier' => 3,
                'allow_top_ups' => true,
                // Re-derived with the allowance (ADR-010): ~1.24x the plan's
                // own ₱/USD rate, so topping up stays worse value than the
                // plan it belongs to. Price is unchanged; the dollar grant
                // moves with the allowance.
                'topup_price' => 75000,
                'topup_usd_cents' => 363,
                'topup_max_per_window' => 8,
                'included_seats' => 1,
                'seat_price' => null,
                'sort_order' => 2,
                'limits' => $paidLimits,
                'features' => [
                    ...$baseFeatures,
                    PlanFeatures::FRONTIER_MODEL,
                    PlanFeatures::DEEP_RESEARCH,
                    PlanFeatures::DOCUMENT_INTELLIGENCE,
                    PlanFeatures::INTEGRATIONS,
                ],
            ],
            [
                'slug' => Plan::SLUG_FIRM,
                'name' => 'Firm',
                'price' => 799900,
                'price_annual' => 7999000,
                'annual_only' => true,
                'overage_price' => null,
                // 20x Standard's AI usage allowance: $103/mo shared across
                // the workspace at the budgeting FX rate — one team pool,
                // not three seat wallets. Three people for ₱7,999, against
                // ₱7,497 for three separate
                // Pro accounts that cannot share a matter between them. The
                // fourth seat onwards costs less than a Pro subscription.
                // 10x Standard, not 20x: at 20x the shared pool cost more than
                // the price covered once payment fees were counted, so a Firm team
                // that used its whole allowance put the plan underwater. See
                // `artisan costing:earnings`.
                'ai_budget_usd_cents' => 5150,
                'ai_usage_multiplier' => 10,
                'allow_top_ups' => true,
                // Repriced with the allowance (₱2,100 → ₱4,200) and again when
                // the price rose to ₱7,999 (₱4,200 → ₱4,800). At a fixed $25
                // grant the pack must track the plan's own ₱/USD rate to keep
                // its ~1.24x premium; had it stayed at ₱4,200 the premium
                // eroded to ~1.08x once the plan price rose (ADR-010).
                'topup_price' => 480000,
                'topup_usd_cents' => 2500,
                'topup_max_per_window' => 8,
                'included_seats' => 3,
                'seat_price' => 199900,
                'sort_order' => 3,
                // Spend is pooled across the organization's active members
                // (see AiBudget), so the team shares one allowance rather
                // than drawing on per-seat wallets.
                'limits' => $paidLimits,
                'features' => [
                    ...$baseFeatures,
                    PlanFeatures::FRONTIER_MODEL,
                    PlanFeatures::DEEP_RESEARCH,
                    PlanFeatures::DOCUMENT_INTELLIGENCE,
                    PlanFeatures::INTEGRATIONS,
                    PlanFeatures::TEAMS,
                    PlanFeatures::SUPPORT_24_7,
                    PlanFeatures::GUIDED_SETUP,
                    PlanFeatures::TEAM_TRAINING,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            $record = Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                // Union, not merge: a plan above that sets `is_active` itself
                // keeps its own value.
                $plan + [
                    'currency' => 'PHP',
                    'interval' => Plan::INTERVAL_MONTHLY,
                    'annual_only' => false,
                    'is_active' => true,
                ],
            );

            // Nothing is ever charged for a free plan, so it needs no gateway
            // plan behind it.
            if ($record->price > 0) {
                $this->syncPayMongoPlan($record);
            }
        }
    }

    /**
     * Provision the matching PayMongo billing plans for the supported intervals and
     * persist their ids so a subscription can be created with just a
     * customer_id and plan_id.
     */
    protected function syncPayMongoPlan(Plan $plan): void
    {
        foreach ($plan->supportedIntervals() as $interval) {
            $this->provisionPlan($plan, $interval);
        }
    }

    protected function provisionPlan(Plan $plan, string $interval): void
    {
        $column = $interval === Plan::INTERVAL_ANNUAL
            ? 'paymongo_plan_id_annual'
            : 'paymongo_plan_id';

        // A gateway plan is an immutable price object, so an id that is already
        // set describes a price that may no longer be this plan's. Repricing
        // therefore has to clear these columns to take effect — see the
        // migration that renames Starter for why that is deliberate.
        if ($plan->{$column} !== null) {
            return;
        }

        if (config('paymongo.secret_key') === '') {
            return;
        }

        try {
            $paymongoPlan = (new PaymongoClient)->createPlan(
                $plan->name,
                $plan->priceForInterval($interval),
                "{$plan->name} ".($interval === Plan::INTERVAL_ANNUAL ? 'annual' : 'monthly').' subscription',
                [
                    'slug' => $plan->slug,
                    'interval' => $interval,
                    'limits' => json_encode($plan->limits ?? [], JSON_THROW_ON_ERROR),
                    'features' => json_encode($plan->features ?? [], JSON_THROW_ON_ERROR),
                    'overage_price' => $plan->overage_price,
                ],
                interval: $interval === Plan::INTERVAL_ANNUAL ? 'yearly' : 'monthly',
            );

            $plan->update([$column => $paymongoPlan['id']]);
        } catch (\Throwable $e) {
            Log::warning("Could not provision PayMongo plan for {$plan->slug} ({$interval}): {$e->getMessage()}");
        }
    }
}
