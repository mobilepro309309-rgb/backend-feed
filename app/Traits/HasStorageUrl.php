<?php

namespace App\Traits;

use App\Contracts\StorageServiceInterface;

trait HasStorageUrl
{
    protected array $storageUrlFields = [];

    protected function resolveStorageUrl(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return app(StorageServiceInterface::class)->getPublicUrl($value);
    }

    public function toArray()
    {
        $attributes = parent::toArray();

        foreach ($this->getStorageUrlFields() as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = $this->resolveStorageUrl($attributes[$field]);
            }
        }

        return $attributes;
    }

    protected function getStorageUrlFields(): array
    {
        return $this->storageUrlFields !== []
            ? $this->storageUrlFields
            : $this->getDefaultStorageUrlFields();
    }

    protected function getDefaultStorageUrlFields(): array
    {
        return ['image_url', 'avatar', 'avatar_url', 'cover_url', 'file_path', 'path', 'url'];
    }
}
