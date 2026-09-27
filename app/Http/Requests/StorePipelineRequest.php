<?php

namespace App\Http\Requests;

use App\Models\PipelineStage;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePipelineRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_default' => ['sometimes', 'boolean'],
            'stages' => ['sometimes', 'array', 'list', 'min:1'],
            'stages.*' => ['array:name,outcome_kind'],
            'stages.*.name' => ['required', 'string', 'max:120'],
            'stages.*.outcome_kind' => ['required', Rule::in(PipelineStage::OUTCOME_KINDS)],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function allowedFields(): array
    {
        return ['name', 'description', 'is_default', 'stages'];
    }
}
