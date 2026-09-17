<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'user_id',
    'plan_id',
    'pending_plan_id',
    'pending_plan_interval',
    'pending_plan_checkout_url',
    'interval',
    'gateway',
    'paymongo_subscription_id',
    'paymongo_customer_id',
    'lemonsqueezy_subscription_id',
    'lemonsqueezy_customer_id',
    'paypal_subscription_id',
    'paypal_last_event_at',
    'paypal_last_event_id',
    'status',
    'seats_purchased',
    'price_per_seat',
    'current_period_start',
    'current_period_end',
    'trial_ends_at',
    'trial_code_id',
    'trial_warned_at',
    'cancelled_at',
    'topup_enabled',
    'topup_cap_pesos',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public const GATEWAY_PAYMONGO = 'paymongo';

    public const GATEWAY_LEMONSQUEEZY = 'lemonsqueezy';

    public const GATEWAY_PAYPAL = 'paypal';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_INCOMPLETE_CANCELLED = 'incomplete_cancelled';

    public const STATUS_ACTIVE = 'active';

    /** A code-granted free trial. Grants access until `trial_ends_at`. */
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PAUSED = 'paused';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'trial_ends_at' => 'datetime',
            'trial_warned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paypal_last_event_at' => 'datetime',
            'seats_purchased' => 'integer',
            'price_per_seat' => 'integer',
            'topup_enabled' => 'boolean',
            'topup_cap_pesos' => 'integer',
        ];
    }

    /**
     * The organization this subscription belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The user this subscription belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The plan this subscription is on.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The plan awaiting PayPal approval, if a revision is in flight.
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /**
     * Whether the subscription currently grants full access.
     *
     * A trial counts, but only until it lapses — the row is left in place once
     * it does, so the user keeps their organization and history and simply
     * loses access until they subscribe.
     */
    public function isActive(): bool
    {
        if ($this->status === self::STATUS_ACTIVE) {
            return true;
        }

        return $this->onTrial();
    }

    /**
     * Whether this is a trial that has not yet lapsed.
     */
    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /**
     * Whole days left on the trial, floored at zero. Null when not a trial.
     */
    public function trialDaysRemaining(): ?int
    {
        if ($this->status !== self::STATUS_TRIALING || $this->trial_ends_at === null) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInDays($this->trial_ends_at, false)));
    }

    /**
     * The code-granted trial this subscription came from, if any.
     */
    public function trialCode(): BelongsTo
    {
        return $this->belongsTo(TrialCode::class);
    }

    /**
     * The billing events recorded against this subscription.
     */
    public function billingEvents(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }

    /**
     * The amount the next invoice should bill for, in centavos: the plan's
     * list price, plus the seats bought on top of the ones it bundles.
     *
     * The list price already covers `included_seats` — Firm is ₱11,000 for
     * three people — so those seats are not billed again. Only the extras
     * carry the per-seat rate, and a subscription sitting on its bundled
     * seats invoices the plan price flat.
     *
     * Subscriptions written before the seat price was stamped onto the row
     * carry no `price_per_seat`; the plan's own `seat_price` is what an extra
     * seat costs there, so it stands in.
     */
    public function nextInvoiceAmount(): int
    {
        $plan = $this->plan;

        // Without a plan there is no list price to start from, so the seats
        // are all this subscription can be billed on.
        if ($plan === null) {
            return $this->seats_purchased * ($this->price_per_seat ?? 0);
        }

        $extraSeats = max(0, $this->seats_purchased - ($plan->included_seats ?? 1));
        $pricePerSeat = $this->price_per_seat ?? $plan->seat_price ?? 0;

        return $plan->priceForInterval($this->interval ?? 'monthly') + $extraSeats * $pricePerSeat;
    }

    /**
     * The provider-side subscription id for the subscription's gateway.
     */
    public function gatewaySubscriptionId(): ?string
    {
        return match ($this->gateway) {
            self::GATEWAY_LEMONSQUEEZY => $this->lemonsqueezy_subscription_id,
            self::GATEWAY_PAYPAL => $this->paypal_subscription_id,
            default => $this->paymongo_subscription_id,
        };
    }
}
