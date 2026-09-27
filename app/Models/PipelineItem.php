<?php

namespace App\Models;

use Database\Factories\PipelineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'source', 'note', 'next_action_at'])]
class PipelineItem extends Model
{
    /** @use HasFactory<PipelineItemFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'next_action_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(PipelineStageChange::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Restrict items through their pipeline's established tenant scope.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('pipeline', function (Builder $pipeline) use ($user): void {
            $pipeline->visibleTo($user);
        })->whereHas('client', function (Builder $client) use ($user): void {
            $client->visibleTo($user);
        });
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
