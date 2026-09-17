<?php

namespace App\Http\Requests;

class UpdatePipelineItemRequest extends StorePipelineItemRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:120'],
            'note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'next_action_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['title', 'source', 'note', 'next_action_at'];
    }
}
