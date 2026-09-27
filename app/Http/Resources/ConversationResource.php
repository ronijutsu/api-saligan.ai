<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'case_id' => $this->case_id,
            'case_tags' => $this->whenLoaded('case', fn (): array => $this->case?->tags ?? []),
            'title' => $this->title,
            'purpose' => $this->purpose,
            'provider' => $this->provider?->value,
            'pinned_at' => $this->pinned_at,
            'pinned' => $this->pinned_at !== null,
            'messages_count' => $this->whenCounted('messages', fn (int $count) => $count),
            'tags' => LabelResource::collection($this->whenLoaded('labels')),
            'last_message_at' => $this->last_message_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
