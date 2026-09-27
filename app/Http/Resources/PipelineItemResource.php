<?php

namespace App\Http\Resources;

use App\Models\PipelineItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PipelineItem
 */
class PipelineItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canMutate = $user?->can('update', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'pipeline_id' => $this->pipeline_id,
            'stage_id' => $this->stage_id,
            'owner_id' => $this->owner_user_id,
            'title' => $this->title,
            'source' => $this->source,
            'note' => $this->note,
            'next_action_at' => $this->next_action_at,
            'archived_at' => $this->archived_at,
            'client' => new ClientResource($this->whenLoaded('client')),
            'pipeline' => new PipelineResource($this->whenLoaded('pipeline')),
            'stage' => new PipelineStageResource($this->whenLoaded('stage')),
            'capabilities' => [
                'update' => $canMutate,
                'archive' => $canMutate && $this->archived_at === null,
                'restore' => $canMutate && $this->archived_at !== null,
                'move' => $user?->can('move', $this->resource) ?? false,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
