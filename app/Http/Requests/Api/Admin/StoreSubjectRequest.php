<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grade_id' => ['nullable', 'integer', 'exists:grades,id', 'required_without:track_id'],
            'track_id' => ['nullable', 'integer', 'exists:tracks,id', 'required_without:grade_id'],
            'code' => ['nullable', 'string', 'max:255', 'unique:subjects,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'education_type' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}