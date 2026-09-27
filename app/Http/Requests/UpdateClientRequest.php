<?php

namespace App\Http\Requests;

class UpdateClientRequest extends StoreClientRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return StoreClientRequest::clientRules(true);
    }
}
