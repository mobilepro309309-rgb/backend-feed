<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\Core\BaseFormRequest;

class LoginRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => trim((string) $this->input('phone', $this->input('identifier', ''))),
            'device_id' => trim((string) $this->input('device_id', '')),
            'device_identifier' => trim((string) $this->input('device_identifier', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
            'fcm_token' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string'],
            'device_identifier' => ['nullable', 'string'],
            'device_type' => ['nullable', 'string', 'in:android,ios,web'],
        ];
    }
}
