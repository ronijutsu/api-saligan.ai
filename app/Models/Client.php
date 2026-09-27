<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['client_type', 'display_name', 'contact', 'notes', 'lifecycle'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, HasUuids;

    public const TYPES = ['person', 'organization'];

    public const LIFECYCLES = ['prospect', 'active', 'former'];

    protected function casts(): array
    {
        return [
            'contact' => 'encrypted:array',
            'notes' => 'encrypted',
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

    public function cases(): BelongsToMany
    {
        return $this->belongsToMany(LegalCase::class, 'case_client', 'client_id', 'case_id')
            ->using(CaseClient::class)
            ->withPivot(['relationship_type', 'is_primary', 'attached_by_user_id'])
            ->withTimestamps();
    }

    public function caseClients(): HasMany
    {
        return $this->hasMany(CaseClient::class, 'client_id');
    }

    public function pipelineItems(): HasMany
    {
        return $this->hasMany(PipelineItem::class, 'client_id');
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
