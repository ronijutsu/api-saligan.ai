<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canMutate = $user?->can('update', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'client_type' => $this->client_type,
            'display_name' => $this->display_name,
            'lifecycle' => $this->lifecycle,
            'organization_id' => $this->organization_id,
            'owner_id' => $this->owner_user_id,
            'contact' => $this->contact ?? [],
            'notes' => $this->notes,
            'archived_at' => $this->archived_at,
            'pipeline_items_count' => $this->pipeline_items_count ?? 0,
            'linked_cases_count' => $this->linked_cases_count ?? 0,
            'pipeline_items' => $this->whenLoaded('pipelineItems', fn () => $this->pipelineItems->map(fn ($item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'pipeline' => $item->pipeline ? ['name' => $item->pipeline->name] : null,
                'stage' => $item->stage ? ['name' => $item->stage->name] : null,
                'updated_at' => $item->updated_at,
            ])->values()),
            'linked_cases' => $this->whenLoaded('caseClients', fn () => $this->caseClients->map(fn ($link): array => [
                'case_id' => $link->case_id,
                'client_id' => $link->client_id,
                'relationship_type' => $link->relationship_type,
                'relationship_label' => $link->relationshipLabel(),
                'is_primary' => (bool) $link->is_primary,
                'attached_by_id' => $link->attached_by_user_id,
                'created_at' => $link->created_at,
                'updated_at' => $link->updated_at,
                'case' => $link->case ? [
                    'id' => $link->case->id,
                    'title' => $link->case->title,
                    'reference' => $link->case->reference,
                    'status' => $link->case->status,
                    'archived_at' => $link->case->archived_at,
                ] : null,
            ])->values()),
            'capabilities' => [
                'update' => $canMutate,
                'archive' => $canMutate && $this->archived_at === null,
                'restore' => $canMutate && $this->archived_at !== null,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
