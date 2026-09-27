<?php

namespace App\Models;

use Database\Factories\SubscriptionWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'subscription_id',
    'window_start',
    'window_end',
    'budget_usd',
    'topup_usd',
    'used_usd',
    'warned_80_at',
])]
class SubscriptionWindow extends Model
{
    /** @use HasFactory<SubscriptionWindowFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'budget_usd' => 'float',
            'topup_usd' => 'float',
            'used_usd' => 'float',
            'warned_80_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(AiUsage::class, 'subscription_window_id');
    }

    /**
     * The allowance a pack may be bought against, in USD.
     *
     * The gate, the meter, and the warning threshold all have to read this
     * rather than `budget_usd` alone, or a customer who paid for extra usage
     * would still be refused by a comparison that cannot see it.
     */
    public function effectiveBudgetUsd(): float
    {
        return (float) $this->budget_usd + (float) $this->topup_usd;
    }

    /**
     * Share of the allowance spent, uncapped so overshoot stays visible instead
     * of rendering as exactly full.
     */
    public function percentUsed(): float
    {
        $budget = $this->effectiveBudgetUsd();

        if ($budget <= 0) {
            return 0.0;
        }

        return $this->used_usd / $budget * 100;
    }

    public function isExhausted(): bool
    {
        return $this->effectiveBudgetUsd() > 0
            && $this->used_usd >= $this->effectiveBudgetUsd();
    }

    public function isWarning(): bool
    {
        return ! $this->isExhausted() && $this->percentUsed() >= 80.0;
    }
}
