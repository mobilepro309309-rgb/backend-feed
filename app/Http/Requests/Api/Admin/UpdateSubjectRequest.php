<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grade_id' => ['sometimes', 'nullable', 'integer', 'exists:grades,id', 'required_without:track_id'],
            'track_id' => ['sometimes', 'nullable', 'integer', 'exists:tracks,id', 'required_without:grade_id'],
            'code' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('subjects', 'code')->ignore($this->route('subject'))],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'education_type' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $subject = $this->route('subject');
            $gradeId = $this->input('grade_id', $subject?->grade_id);
            $trackId = $this->input('track_id', $subject?->track_id);

            if ($gradeId === null && $trackId === null) {
                $validator->errors()->add('grade_id', 'يجب ربط المادة بصف أو مسار دراسي واحد على الأقل.');
            }
        });
    }
}