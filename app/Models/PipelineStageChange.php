<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pipeline_item_id', 'previous_stage_id', 'new_stage_id', 'actor_user_id', 'request_id'])]
class PipelineStageChange extends Model
{
    use HasFactory, HasUuids;

    public function item(): BelongsTo
    {
        return $this->belongsTo(PipelineItem::class, 'pipeline_item_id');
    }

    public function previousStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'previous_stage_id');
    }

    public function newStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'new_stage_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
