<?php

namespace App\Models;

use App\Enums\TopUpStatus;
use Database\Factories\AiTopUpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A purchased AI top-up pack (ADR-010).
 *
 * The purchase record, not the allowance: it says what was charged and what it
 * granted, while the allowance itself is carried on
 * `subscription_windows.topup_usd` and expires with that window.
 */
#[Fillable([
    'subscription_id',
    'subscription_window_id',
    'user_id',
    'organization_id',
    'packs',
    'amount_pesos',
    'usd_cents',
    'status',
    'gateway',
    'gateway_payment_intent_id',
    'gateway_payment_id',
    'checkout_url',
    'paid_at',
    'metadata',
])]
class AiTopUp extends Model
{
    /** @use HasFactory<AiTopUpFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'packs' => 'integer',
            'amount_pesos' => 'integer',
            'usd_cents' => 'integer',
            'status' => TopUpStatus::class,
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The subscription this pack was bought on.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The window the pack credits.
     */
    public function window(): BelongsTo
    {
        return $this->belongsTo(SubscriptionWindow::class, 'subscription_window_id');
    }

    /**
     * Who paid for it.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this pack has already granted its allowance. Crediting keys on
     * this, so a replayed webhook cannot top the window up twice.
     */
    public function isSettled(): bool
    {
        return $this->status === TopUpStatus::Paid;
    }

    /**
     * The allowance this pack grants, in USD.
     */
    public function allowanceUsd(): float
    {
        return $this->usd_cents / 100;
    }
}
