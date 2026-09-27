<?php

namespace App\Http\Requests;

class UpdatePipelineRequest extends StorePipelineRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['name', 'description', 'is_default'];
    }
}
