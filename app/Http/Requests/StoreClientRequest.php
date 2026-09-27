<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function withValidator(Validator $validator): void
    {
        $allowed = ['client_type', 'display_name', 'lifecycle', 'contact', 'notes'];

        $validator->after(function (Validator $validator) use ($allowed): void {
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'This field is not permitted.');
            }
        });
    }

    public function rules(): array
    {
        return self::clientRules();
    }

    public static function clientRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'client_type' => [$required, Rule::in(Client::TYPES)],
            'display_name' => [$required, 'string', 'max:255'],
            'lifecycle' => ['sometimes', Rule::in(Client::LIFECYCLES)],
            'contact' => ['sometimes', 'array:email,phone'],
            'contact.email' => ['sometimes', 'email', 'max:255'],
            'contact.phone' => ['sometimes', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }
}
