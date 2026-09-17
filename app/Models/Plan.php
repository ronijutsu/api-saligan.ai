<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug',
    'name',
    'price',
    'price_annual',
    'overage_price',
    'ai_budget_usd_cents',
    'ai_usage_multiplier',
    'included_seats',
    'seat_price',
    'currency',
    'interval',
    'limits',
    'features',
    'paymongo_plan_id',
    'paymongo_plan_id_annual',
    'lemonsqueezy_variant_id',
    'lemonsqueezy_variant_id_annual',
    'is_active',
    'contact_sales',
    'annual_only',
    'sort_order',
    'allow_top_ups',
    'topup_price',
    'topup_usd_cents',
    'topup_max_per_window',
])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The plan an automatic or code-granted free trial runs on. Never sold: it
     * is seeded inactive so it stays out of paid pricing and checkout.
     */
    public const SLUG_TRIAL = 'trial';

    public const SLUG_STANDARD = 'standard';

    public const SLUG_PRO = 'pro';

    public const SLUG_FIRM = 'firm';

    /**
     * Legacy slug retained so historical migrations can still run against a
     * fresh database. It is no longer seeded or exposed as an active tier.
     */
    public const SLUG_BUSINESS = 'business';

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_ANNUAL = 'annual';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'price_annual' => 'integer',
            'overage_price' => 'integer',
            'ai_budget_usd_cents' => 'integer',
            'ai_usage_multiplier' => 'integer',
            'included_seats' => 'integer',
            'seat_price' => 'integer',
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'contact_sales' => 'boolean',
            'annual_only' => 'boolean',
            'sort_order' => 'integer',
            'lemonsqueezy_variant_id' => 'integer',
            'lemonsqueezy_variant_id_annual' => 'integer',
            'allow_top_ups' => 'boolean',
            'topup_price' => 'integer',
            'topup_usd_cents' => 'integer',
            'topup_max_per_window' => 'integer',
        ];
    }

    /**
     * Whether this tier may buy extra AI usage at all (ADR-010), and whether it
     * is priced to do so. Both halves are required: a plan can be marked
     * eligible before its pack price is agreed.
     */
    public function canBuyTopUps(): bool
    {
        return (bool) $this->allow_top_ups
            && $this->topup_price !== null
            && $this->topup_usd_cents !== null
            && $this->topup_price > 0
            && $this->topup_usd_cents > 0;
    }

    /**
     * The subscriptions using this plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Whether the plan can be bought without talking to anyone. A
     * contact-sales plan carries no list price, so quoting one — in checkout,
     * on the pricing page, or in an email — would be quoting a number nobody
     * agreed to.
     */
    public function isSelfServe(): bool
    {
        return ! $this->contact_sales;
    }

    /**
     * Whether the plan can be purchased or changed to on the given interval.
     */
    public function supportsInterval(string $interval): bool
    {
        return $interval === self::INTERVAL_ANNUAL
            || ($interval === self::INTERVAL_MONTHLY && ! $this->annual_only);
    }

    /**
     * The billing intervals that should have provider price objects provisioned.
     *
     * @return list<string>
     */
    public function supportedIntervals(): array
    {
        return $this->annual_only
            ? [self::INTERVAL_ANNUAL]
            : [self::INTERVAL_MONTHLY, self::INTERVAL_ANNUAL];
    }

    /**
     * The price formatted as a PHP peso string, e.g. ₱1,500.
     */
    public function priceLabel(): string
    {
        if ($this->contact_sales) {
            return 'Custom';
        }

        return '₱'.number_format($this->price / 100);
    }

    /**
     * The annual price formatted as a PHP peso string, e.g. ₱14,940.
     */
    public function priceAnnualLabel(): string
    {
        if ($this->contact_sales) {
            return 'Custom';
        }

        return '₱'.number_format(($this->price_annual ?? $this->price * 12) / 100);
    }

    /**
     * The price for the given billing interval, in centavos.
     */
    public function priceForInterval(string $interval): int
    {
        if ($interval === self::INTERVAL_ANNUAL) {
            return $this->price_annual ?? $this->price * 12;
        }

        return $this->price;
    }

    /**
     * The stored PayMongo plan id for the given billing interval.
     */
    public function paymongoPlanIdForInterval(string $interval): ?string
    {
        return $interval === self::INTERVAL_ANNUAL
            ? $this->paymongo_plan_id_annual
            : $this->paymongo_plan_id;
    }

    /**
     * The LemonSqueezy variant id for the given billing interval.
     */
    public function lemonSqueezyVariantIdForInterval(string $interval): ?int
    {
        return $interval === self::INTERVAL_ANNUAL
            ? $this->lemonsqueezy_variant_id_annual
            : $this->lemonsqueezy_variant_id;
    }

    /**
     * The monthly AI spend allowance in USD, or null when the plan is not
     * spend-gated (contract tiers negotiated per deal).
     */
    public function aiBudgetUsd(): ?float
    {
        return $this->ai_budget_usd_cents === null ? null : $this->ai_budget_usd_cents / 100;
    }

    /**
     * The monthly AI spend allowance converted to PHP using the configured
     * budgeting FX rate.
     */
    public function aiBudgetPesos(): ?float
    {
        $usd = $this->aiBudgetUsd();

        return $usd === null ? null : $usd * (float) config('billing.fx_usd_php', 65.0);
    }

    /**
     * The plan's AI usage allowance relative to Standard, or null for a
     * contract/trial tier outside the paid ladder.
     */
    public function aiUsageMultiplier(): ?int
    {
        return $this->ai_usage_multiplier;
    }

    /**
     * The extra-usage pack as the marketing table reads it, or null when the
     * tier cannot buy one (ADR-010). The price is the customer-facing fact; the
     * USD it grants stays internal, like the budget it tops up.
     */
    public function topUpLabel(): ?string
    {
        if (! $this->canBuyTopUps()) {
            return null;
        }

        return '₱'.number_format($this->topup_price / 100).' per pack';
    }

    /**
     * The allowance tier as the customer reads it: no internal budget figure
     * is quoted on the pricing table.
     */
    public function aiBudgetLabel(): ?string
    {
        if ($this->ai_budget_usd_cents === null) {
            return null;
        }

        $multiplier = $this->aiUsageMultiplier();

        return $multiplier === null ? 'Included usage' : "{$multiplier}x usage";
    }

    /**
     * The per-message overage price formatted as a peso string, e.g. ₱3.50.
     */
    public function overageLabel(): string
    {
        $amount = ($this->overage_price ?? 0) / 100;

        return '₱'.number_format($amount, $amount == round($amount) ? 0 : 2);
    }

    /**
     * Whether the plan sells seats beyond the ones its list price covers.
     */
    public function sellsSeats(): bool
    {
        return $this->seat_price !== null;
    }

    /**
     * The per-seat price formatted as a peso string, or null when the plan
     * does not sell seats — the distinction the pricing page needs to choose
     * between quoting an add-on and saying there is none.
     */
    public function seatPriceLabel(): ?string
    {
        if (! $this->sellsSeats()) {
            return null;
        }

        return '₱'.number_format($this->seat_price / 100);
    }
}
