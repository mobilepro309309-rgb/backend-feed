<?php

namespace App\Contracts;

interface StorageServiceInterface
{
    public function generatePresignedUrl(string $fileName, string $fileType, string $category, int $fileSize): array;
    public function deleteFile(string $path, string $bucket = 'public-assets'): bool;
    public function getPublicUrl(string $path): string;
    public function getTemporaryUrl(string $path, int $expirationMinutes = 15): string;
}
