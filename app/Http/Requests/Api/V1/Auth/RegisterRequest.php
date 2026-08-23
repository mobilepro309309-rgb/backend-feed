<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\Core\BaseFormRequest;

class RegisterRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => strip_tags(trim((string) $this->input('name', ''))),
            'phone' => trim((string) $this->input('phone', '')),
            'referral_code' => strtoupper(trim((string) $this->input('referral_code', ''))),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'unique:users'],
            'password' => ['required', 'string', 'min:6'],
            'gender' => ['nullable', 'string', 'max:255'],
            'school_grade' => ['nullable', 'string', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'village' => ['nullable', 'string', 'max:255'],
            'referral_code' => ['nullable', 'string', 'max:20', 'exists:users,referral_code'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'device_identifier' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string'],
        ];
    }
}
