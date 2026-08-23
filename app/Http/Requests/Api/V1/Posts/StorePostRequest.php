<?php

namespace App\Http\Requests\Api\V1\Posts;

use App\Http\Requests\Api\V1\Core\BaseFormRequest;

class StorePostRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'content' => $this->filled('content') ? strip_tags(trim((string) $this->input('content'))) : null,
            'subject' => trim((string) $this->input('subject', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:10000', 'required_without:attachments'],
            'subject' => ['required', 'string', 'max:120'],
            'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'attachments' => ['nullable', 'array', 'max:10', 'required_without:content'],
            'attachments.*.id' => ['nullable', 'string', 'max:255'],
            'attachments.*.name' => ['nullable', 'string', 'max:255'],
            'attachments.*.uri' => ['nullable', 'url', 'max:2048'],
            'attachments.*.mimeType' => ['nullable', 'string', 'max:100'],
            'attachments.*.size' => ['nullable', 'integer', 'min:0', 'max:10485760'],
        ];
    }
}
