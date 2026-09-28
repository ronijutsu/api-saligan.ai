<?php

namespace App\Http\Requests;

use App\Exceptions\CrmConflictException;
use App\Models\PipelineStage;
use App\Support\PipelineTemplate;
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

            if ($this->filled('template_key') && $this->has('stages')) {
                throw new CrmConflictException(
                    'template_input_conflict',
                    'template_key and stages cannot be supplied together.',
                    422,
                );
            }

            if ($this->filled('template_key') && PipelineTemplate::find($this->string('template_key')->toString()) === null) {
                throw new CrmConflictException(
                    'invalid_template',
                    'The selected pipeline template is not approved.',
                    422,
                    ['template_key' => ['The selected pipeline template is invalid.']],
                );
            }
        });
    }

    public function rules(): array
    {
        return [
            'name' => ['required_without:template_key', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_default' => ['sometimes', 'boolean'],
            'stages' => ['sometimes', 'array', 'list', 'min:1'],
            'template_key' => ['sometimes', 'string', 'max:80'],
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
        return ['name', 'description', 'is_default', 'stages', 'template_key'];
    }
}
