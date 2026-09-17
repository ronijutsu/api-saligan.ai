<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\HasLabels;
use App\Services\Cases\CaseDigestService;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'case_id',
    'title',
    'original_filename',
    'storage_path',
    'mime_type',
    'status',
    'error_message',
    'embedding_model',
    'ai_usage_id',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasLabels;
    use HasUuids;

    protected static function booted(): void
    {
        static::saved(function (Document $document): void {
            if (! $document->wasRecentlyCreated && ! $document->wasChanged([
                'case_id',
                'title',
                'status',
                'digest',
                'digest_generated_at',
            ])) {
                return;
            }

            $case = $document->case;

            if ($case !== null) {
                app(CaseDigestService::class)->queue($case);
            }
        });

        static::deleted(function (Document $document): void {
            $case = $document->case;

            if ($case !== null) {
                app(CaseDigestService::class)->queue($case);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'digest_generated_at' => 'datetime',
        ];
    }

    /**
     * The user who uploaded this document.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The case this document is attached to, if any.
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    /**
     * The chunks extracted from this document.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    /**
     * Whether the user may read this document: they uploaded it, or it is
     * attached to a case they are on.
     *
     * A case's documents are its shared evidence — an assignee who cannot open
     * the file shelf cannot work the matter. A document with no case stays
     * private to whoever uploaded it.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->case !== null && $this->case->isAccessibleBy($user);
    }

    /**
     * Whether the user may delete this document. The same question as reading
     * it: everyone on a case works the same file shelf, so a colleague who can
     * open a document can also take it off the shelf. Filing is shared work,
     * and a document nobody but the uploader can remove is one the rest of the
     * team has to work around.
     */
    public function isDeletableBy(User $user): bool
    {
        return $this->isAccessibleBy($user);
    }

    /**
     * Scope to the documents a user may open: the ones they uploaded, plus
     * everything filed into a case they are on.
     *
     * The mirror of `isAccessibleBy` for listings. Without it the document
     * library answers only with what the caller uploaded, so an assignee sees
     * an empty shelf for a matter that is full of their colleague's evidence.
     *
     * @param  Builder<Document>  $query
     */
    public function scopeVisibleTo($query, User $user): void
    {
        $query->where(function ($scoped) use ($user) {
            $scoped->where('documents.user_id', $user->id)
                ->orWhereHas('case', fn ($case) => $case->visibleTo($user));
        });
    }
}
