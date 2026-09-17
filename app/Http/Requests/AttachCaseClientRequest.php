<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachCaseClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('is_primary') !== ($this->input('relationship_type') === 'primary_client')) {
                $validator->errors()->add('is_primary', 'is_primary must agree with relationship_type.');
            }
            $allowed = $this->route('client') !== null
                ? ['case_id', 'relationship_type', 'is_primary']
                : ['client_id', 'relationship_type', 'is_primary'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'case_id' => [$this->route('client') !== null ? 'required' : 'sometimes', 'uuid'],
            'client_id' => [$this->route('case') !== null ? 'required' : 'sometimes', 'uuid'],
            'relationship_type' => ['required', Rule::in(['primary_client', 'additional_client'])],
            'is_primary' => ['required', 'boolean'],
        ];
    }
}
