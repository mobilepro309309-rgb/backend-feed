<?php

namespace App\Http\Requests\Api\V1\Posts;

use App\Http\Requests\Api\V1\Core\BaseFormRequest;

class ModeratePostRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'action' => strtolower(trim((string) $this->input('action', $this->input('status', '')))),
        ]);
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,publish,published,reject,rejected,delete'],
        ];
    }
}
