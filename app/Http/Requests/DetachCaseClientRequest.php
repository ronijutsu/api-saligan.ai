<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class DetachCaseClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['replacement_client_id']) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
            }
        });
    }

    public function rules(): array
    {
        return ['replacement_client_id' => ['nullable', 'uuid']];
    }
}
