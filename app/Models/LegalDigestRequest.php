<?php

namespace App\Models;

use App\Services\Crawler\LegalDigestBatcher;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One crawled authority waiting on a batched digest.
 *
 * @see LegalDigestBatcher
 */
#[Fillable([
    'crawled_page_id',
    'excerpt',
    'status',
    'batch_id',
    'error',
    'submitted_at',
    'completed_at',
])]
class LegalDigestRequest extends Model
{
    /** Queued, not yet part of a batch. */
    public const STATUS_PENDING = 'pending';

    /** Sent to the provider; waiting for the batch to end. */
    public const STATUS_SUBMITTED = 'submitted';

    /** Answered, and the digest written to the page. */
    public const STATUS_SUCCEEDED = 'succeeded';

    /**
     * No usable answer. The page keeps no digest, which is the state an inline
     * failure leaves it in too — the reader falls back to full text.
     */
    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(CrawledPage::class, 'crawled_page_id');
    }

    /**
     * The id this request is known by inside its batch. Results come back
     * unordered, so this is what maps an answer to a page.
     */
    public function customId(): string
    {
        return 'ldr_'.$this->id;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    /**
     * Close the request out as answered.
     */
    public function markSucceeded(): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUCCEEDED,
            'error' => null,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Close the request out unanswered. The reason is an operator breadcrumb,
     * not something a reader ever sees.
     */
    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => mb_substr($reason, 0, 255),
            'completed_at' => now(),
        ])->save();
    }
}
