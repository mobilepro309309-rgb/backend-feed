<?php

namespace App\Services;

use Illuminate\Support\Facades\{Log, Storage};
use RuntimeException;

use App\Contracts\StorageServiceInterface;

class CloudflareR2StorageService implements StorageServiceInterface
{
    protected string $disk = 'r2';

    public function generatePresignedUrl(string $fileName, string $fileType, string $category, int $fileSize): array
    {
        $filePath = $fileName;

        if (str_contains($fileName, '::')) {
            [, $filePath] = explode('::', $fileName, 2);
        }

        $bucket = config('filesystems.disks.r2.bucket');

        return $this->generateSignedUploadUrl($bucket, $filePath, 300);
    }

    public function deleteFile(string $path, string $bucket = 'app-storage'): bool
    {
        try {
            $relativePath = ltrim($path, '/');
            return Storage::disk($this->disk)->delete($relativePath);
        } catch (\Throwable $e) {
            Log::error('Cloudflare R2 delete file failed', [
                'error' => $e->getMessage(),
                'path' => $path,
            ]);
            return false;
        }
    }

    public function getPublicUrl(string $path): string
    {
        $publicDomain = trim((string) config('services.cloudflare_r2.public_url', config('filesystems.disks.r2.url', '')));

        if ($publicDomain === '') {
            return Storage::disk($this->disk)->url(ltrim($path, '/'));
        }

        $normalizedPath = ltrim($path, '/');
        $normalizedPath = preg_replace('#^public-assets/?#i', '', $normalizedPath) ?? $normalizedPath;

        return rtrim($publicDomain, '/') . '/' . ltrim($normalizedPath, '/');
    }

    public function getTemporaryUrl(string $path, int $expirationMinutes = 15): string
    {
        try {
            $relativePath = ltrim($path, '/');
            return Storage::disk($this->disk)->temporaryUrl(
                $relativePath,
                now()->addMinutes($expirationMinutes)
            );
        } catch (\Throwable $e) {
            Log::warning('Cloudflare R2 temporary URL fallback to public URL', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return $this->getPublicUrl($path);
        }
    }

    public function generateSignedUploadUrl(string $bucket, string $filePath, int $expiresIn = 300): array
    {
        try {
            $client = Storage::disk($this->disk)->getClient();

            $command = $client->getCommand('PutObject', [
                'Bucket' => config('filesystems.disks.r2.bucket'),
                'Key'    => ltrim($filePath, '/'),
            ]);

            $request = $client->createPresignedRequest($command, "+{$expiresIn} seconds");
            $signedUrl = (string) $request->getUri();

            return [
                'signed_url' => $signedUrl,
                'bucket'     => config('filesystems.disks.r2.bucket'),
                'file_path'  => $filePath,
            ];
        } catch (\Throwable $e) {
            Log::error('Cloudflare R2 presign request failed', [
                'error' => $e->getMessage(),
                'file_path' => $filePath,
            ]);

            throw new RuntimeException('Failed to generate Cloudflare R2 upload URL: ' . $e->getMessage());
        }
    }
}