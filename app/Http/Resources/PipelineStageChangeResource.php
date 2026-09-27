<?php

namespace App\Http\Resources;

use App\Models\PipelineStageChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PipelineStageChange
 */
class PipelineStageChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pipeline_item_id' => $this->pipeline_item_id,
            'previous_stage_id' => $this->previous_stage_id,
            'new_stage_id' => $this->new_stage_id,
            'actor_id' => $this->actor_user_id,
            'request_id' => $this->request_id,
            'previous_stage' => new PipelineStageResource($this->whenLoaded('previousStage')),
            'new_stage' => new PipelineStageResource($this->whenLoaded('newStage')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
