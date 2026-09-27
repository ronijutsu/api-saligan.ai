<?php

namespace App\Models;

use App\Services\Documents\DocumentClassificationBatcher;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document waiting to be filed by a batched classification call.
 *
 * @see DocumentClassificationBatcher
 */
#[Fillable([
    'document_id',
    'excerpt',
    'status',
    'batch_id',
    'error',
    'submitted_at',
    'completed_at',
])]
class DocumentClassificationRequest extends Model
{
    /** Queued, not yet part of a batch. */
    public const STATUS_PENDING = 'pending';

    /** Sent to ai-provider; waiting for the batch to end. */
    public const STATUS_SUBMITTED = 'submitted';

    /** Answered, and the answer applied to the document. */
    public const STATUS_SUCCEEDED = 'succeeded';

    /**
     * No usable answer. The document is left unfiled, which is the same state
     * an inline classification failure leaves it in.
     */
    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            // The excerpt is the opening of the document. Documents are stored
            // encrypted, so this is too.
            'excerpt' => 'encrypted',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * The id this request is known by inside its batch. Results come back
     * unordered, so this is what maps an answer to a document.
     */
    public function customId(): string
    {
        return 'dcr_'.$this->id;
    }

    /**
     * The request id encoded in a batch result's `custom_id`, or null when the
     * id is not one of ours — a batch that was not created by this app, or a
     * result we cannot place.
     */
    public static function idFromCustomId(string $customId): ?int
    {
        if (! str_starts_with($customId, 'dcr_')) {
            return null;
        }

        $id = substr($customId, 4);

        return ctype_digit($id) ? (int) $id : null;
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
     * Close the request out unanswered. The reason is kept short: it is an
     * operator breadcrumb, not something a user ever reads.
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
