<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePipelineItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function withValidator(Validator $validator): void
    {
        $allowed = $this->allowedFields();

        $validator->after(function (Validator $validator) use ($allowed): void {
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'pipeline_id' => ['required', 'uuid'],
            'stage_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:120'],
            'note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'next_action_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function allowedFields(): array
    {
        return ['client_id', 'pipeline_id', 'stage_id', 'title', 'source', 'note', 'next_action_at'];
    }
}
