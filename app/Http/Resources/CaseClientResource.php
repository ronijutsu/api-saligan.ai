<?php

namespace App\Http\Resources;

use App\Models\CaseClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CaseClient */
class CaseClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'case_id' => $this->case_id,
            'client_id' => $this->client_id,
            'relationship_type' => $this->relationship_type,
            'relationship_label' => $this->relationshipLabel(),
            'is_primary' => (bool) $this->is_primary,
            'attached_by_id' => $this->attached_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'case' => new LegalCaseResource($this->whenLoaded('case')),
            'client' => new ClientResource($this->whenLoaded('client')),
        ];
    }
}
