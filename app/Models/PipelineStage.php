<?php

namespace App\Models;

use Database\Factories\PipelineStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'position', 'outcome_kind'])]
class PipelineStage extends Model
{
    /** @use HasFactory<PipelineStageFactory> */
    use HasFactory, HasUuids;

    public const OUTCOME_KINDS = ['open', 'won', 'not_proceeding'];

    /**
     * These labels and outcomes are frozen by ADR-007.
     *
     * @var array<int, array{name: string, outcome_kind: string}>
     */
    public const DEFAULT_STAGES = [
        ['name' => 'New inquiry', 'outcome_kind' => 'open'],
        ['name' => 'Qualification', 'outcome_kind' => 'open'],
        ['name' => 'Consultation', 'outcome_kind' => 'open'],
        ['name' => 'Engagement pending', 'outcome_kind' => 'open'],
        ['name' => 'Won', 'outcome_kind' => 'won'],
        ['name' => 'Not proceeding', 'outcome_kind' => 'not_proceeding'],
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PipelineItem::class, 'stage_id');
    }

    public function changesFrom(): HasMany
    {
        return $this->hasMany(PipelineStageChange::class, 'previous_stage_id');
    }

    public function changesTo(): HasMany
    {
        return $this->hasMany(PipelineStageChange::class, 'new_stage_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function isTerminal(): bool
    {
        return in_array($this->outcome_kind, ['won', 'not_proceeding'], true);
    }
}
