<?php

namespace App\Http\Resources;

use App\Models\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Pipeline
 */
class PipelineResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'organization_id' => $this->organization_id,
            'owner_id' => $this->owner_user_id,
            'is_default' => $this->is_default,
            'template_key' => $this->template_key,
            'auto_provisioned' => $this->auto_provisioned,
            'archived_at' => $this->archived_at,
            'stages' => PipelineStageResource::collection($this->whenLoaded('stages')),
            'capabilities' => [
                'update' => $canMutate,
                'archive' => $canMutate && $this->archived_at === null,
                'restore' => $canMutate && $this->archived_at !== null,
                'configure_stages' => $user?->can('configureStages', $this->resource) ?? false,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
