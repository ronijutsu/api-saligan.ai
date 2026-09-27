<?php

namespace App\Http\Resources;

use App\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PipelineStage
 */
class PipelineStageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'outcome_kind' => $this->outcome_kind,
            'archived_at' => $this->archived_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
