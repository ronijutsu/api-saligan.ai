<?php

namespace App\Models;

use Database\Factories\PipelineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'is_default'])]
class Pipeline extends Model
{
    /** @use HasFactory<PipelineFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Active stages in their stable board order.
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id');
    }

    public function allStages(): HasMany
    {
        return $this->hasMany(PipelineStage::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PipelineItem::class);
    }

    /**
     * Restrict records to the authenticated user's solo or organization scope.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasActiveMembership()) {
            $query->where('organization_id', $user->organization_id);

            return;
        }

        $query->whereNull('organization_id')->where('owner_user_id', $user->id);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_user_id === $user->id;
    }
}
