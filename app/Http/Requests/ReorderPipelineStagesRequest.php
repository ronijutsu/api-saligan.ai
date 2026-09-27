<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReorderPipelineStagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function withValidator(Validator $validator): void
    {
        $allowed = ['stage_ids'];

        $validator->after(function (Validator $validator) use ($allowed): void {
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'stage_ids' => ['required', 'array', 'list', 'min:1'],
            'stage_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
