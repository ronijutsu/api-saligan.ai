<?php

namespace App\Http\Requests;

class UpdatePipelineStageRequest extends StorePipelineStageRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'outcome_kind' => ['sometimes', 'in:open,won,not_proceeding'],
        ];
    }
}
