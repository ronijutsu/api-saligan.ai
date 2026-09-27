<?php

namespace App\Http\Requests;

use App\Exceptions\CrmConflictException;
use App\Support\PipelineTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ProvisionPipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['template_key']) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
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
            'template_key' => ['sometimes', 'string', 'max:80'],
        ];
    }
}
