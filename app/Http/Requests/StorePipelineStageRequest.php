<?php

namespace App\Http\Requests;

use App\Models\PipelineStage;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePipelineStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function withValidator(Validator $validator): void
    {
        $allowed = ['name', 'outcome_kind'];

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
            'outcome_kind' => ['required', Rule::in(PipelineStage::OUTCOME_KINDS)],
        ];
    }
}
