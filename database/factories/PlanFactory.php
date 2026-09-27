<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Support\PlanFeatures;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default plan carries every capability. Most tests want "a user who
     * can use the product" rather than a particular tier, and a default that
     * withheld features would make each of them fail for a reason that has
     * nothing to do with what they are testing. Tests that care about a
     * capability boundary name the tier they mean, or set `features` outright.
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'price' => 150000,
            'currency' => 'PHP',
            'interval' => 'monthly',
            'included_seats' => 1,
            'seat_price' => null,
            'limits' => [
                'active_cases' => 10,
                'documents_uploaded' => 10,
                'messages_used' => 200,
            ],
            'features' => PlanFeatures::capabilities(),
            'annual_only' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * The free trial plan: 10% of Standard's metered allowance, answered by the
     * base model, and inactive so it is only offered by the trial flow.
     */
    public function trial(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_TRIAL,
            'name' => 'Free trial',
            'price' => 0,
            'price_annual' => 0,
            'overage_price' => null,
            'ai_budget_usd_cents' => 52,
            'ai_usage_multiplier' => null,
            'included_seats' => 1,
            'seat_price' => null,
            'sort_order' => 0,
            'is_active' => false,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => 5,
                'messages_used' => 30,
            ],
            'features' => [
                PlanFeatures::DRAFTING,
                PlanFeatures::EXPORTS,
                PlanFeatures::PDF_DOCUMENTS,
                PlanFeatures::WEB_SEARCH,
            ],
        ]);
    }

    /**
     * The Standard plan: volume on the base model, capped, single seat.
     */
    public function standard(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_STANDARD,
            'name' => 'Standard',
            'price' => 150000,
            'price_annual' => 1500000,
            'overage_price' => null,
            'ai_budget_usd_cents' => 515,
            'ai_usage_multiplier' => 1,
            'included_seats' => 1,
            'seat_price' => null,
            'annual_only' => false,
            'sort_order' => 1,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => 50,
                'messages_used' => 250,
            ],
            'features' => [
                PlanFeatures::DRAFTING,
                PlanFeatures::EXPORTS,
                PlanFeatures::WEB_SEARCH,
            ],
        ]);
    }

    /**
     * The Pro plan: the frontier model, deep research, scan reading.
     */
    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_PRO,
            'name' => 'Pro',
            'price' => 350000,
            'price_annual' => 3500000,
            'overage_price' => null,
            'ai_budget_usd_cents' => 1500,
            'ai_usage_multiplier' => 3,
            'included_seats' => 1,
            'seat_price' => null,
            'annual_only' => false,
            'sort_order' => 2,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => 200,
                'messages_used' => 300,
            ],
            'features' => [
                PlanFeatures::DRAFTING,
                PlanFeatures::EXPORTS,
                PlanFeatures::PDF_DOCUMENTS,
                PlanFeatures::WEB_SEARCH,
                PlanFeatures::FRONTIER_MODEL,
                PlanFeatures::DEEP_RESEARCH,
                PlanFeatures::DOCUMENT_INTELLIGENCE,
                PlanFeatures::INTEGRATIONS,
            ],
        ]);
    }

    /**
     * The Firm plan: everything Pro has, for a team, with seats to sell.
     */
    public function firm(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_FIRM,
            'name' => 'Firm',
            'price' => 799900,
            'price_annual' => 7999000,
            'overage_price' => null,
            'ai_budget_usd_cents' => 5150,
            'ai_usage_multiplier' => 10,
            'included_seats' => 3,
            'seat_price' => 199900,
            'annual_only' => true,
            'sort_order' => 3,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => null,
                'messages_used' => 300,
            ],
            'features' => [
                PlanFeatures::DRAFTING,
                PlanFeatures::EXPORTS,
                PlanFeatures::PDF_DOCUMENTS,
                PlanFeatures::WEB_SEARCH,
                PlanFeatures::FRONTIER_MODEL,
                PlanFeatures::DEEP_RESEARCH,
                PlanFeatures::DOCUMENT_INTELLIGENCE,
                PlanFeatures::INTEGRATIONS,
                PlanFeatures::TEAMS,
                PlanFeatures::SUPPORT_24_7,
                PlanFeatures::GUIDED_SETUP,
                PlanFeatures::TEAM_TRAINING,
            ],
        ]);
    }

    /**
     * A tier that may sell prepaid extra AI usage (ADR-010). Prices are in the
     * same minor units as `price`; the USD granted is internal.
     */
    public function withTopUps(int $price = 30000, int $usdCents = 125, ?int $maxPerWindow = 8): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_top_ups' => true,
            'topup_price' => $price,
            'topup_usd_cents' => $usdCents,
            'topup_max_per_window' => $maxPerWindow,
        ]);
    }
}
