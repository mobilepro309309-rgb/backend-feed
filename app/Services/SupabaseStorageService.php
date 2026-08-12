<?php

namespace App\Services;

use App\Contracts\StorageServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SupabaseStorageService implements StorageServiceInterface
{
    public function __construct(
        protected string $supabaseUrl,
        protected string $serviceRoleKey,
    ) {
        if (empty($this->supabaseUrl) || empty($this->serviceRoleKey)) {
            throw new RuntimeException('Supabase storage service is not configured properly.');
        }
    }

    public static function fromEnv(): self
    {
        return new self(
            rtrim((string) config('services.supabase.url', ''), '/'),
            (string) config('services.supabase.service_role_key', ''),
        );
    }

    /**
     * Adapter method to match the StorageServiceInterface.
     * Accepts a file identifier which can be either a safe file path or
     * encoded as "{bucket}::{file_path}" to explicitly set the bucket.
     *
     * @param string $fileName Either safe file path or encoded bucket::file_path
     */
    public function generatePresignedUrl(string $fileName, string $fileType, string $category, int $fileSize): array
    {
        $bucket = 'public-assets';
        $filePath = $fileName;

        if (strpos($fileName, '::') !== false) {
            [$bucket, $filePath] = explode('::', $fileName, 2);
        }

        return $this->generateSignedUploadUrl($bucket, $filePath, 300);
    }

    public function deleteFile(string $path, string $bucket = 'public-assets'): bool
    {
        $endpoint = sprintf('%s/storage/v1/object/%s/%s', $this->supabaseUrl, $bucket, ltrim($path, '/'));

        $response = Http::withHeaders([
            'Authorization' => sprintf('Bearer %s', $this->serviceRoleKey),
            'apiKey' => $this->serviceRoleKey,
            'Content-Type' => 'application/json',
        ])->timeout(10)->delete($endpoint);

        if (in_array($response->status(), [200, 204], true)) {
            return true;
        }

        Log::error('Supabase delete file failed', ['status' => $response->status(), 'body' => $response->json(), 'endpoint' => $endpoint]);
        return false;
    }

    public function getPublicUrl(string $path): string
    {
        $publicDomain = trim((string) config('services.storage.public_domain', ''));
        $relativePath = ltrim($path, '/');

        if ($publicDomain !== '') {
            return rtrim($publicDomain, '/') . '/' . $relativePath;
        }

        return $relativePath;
    }

    public function getTemporaryUrl(string $path, int $expirationMinutes = 15): string
    {
        $expiresIn = max(60, min($expirationMinutes * 60, 86400));
        $bucket = 'public-assets';
        $relativePath = ltrim($path, '/');

        if (str_contains($relativePath, '::')) {
            [$bucket, $relativePath] = explode('::', $relativePath, 2);
        }

        $endpoint = sprintf('%s/storage/v1/object/sign/%s/%s', rtrim($this->supabaseUrl, '/'), $bucket, $relativePath);

        $response = Http::withHeaders([
            'Authorization' => sprintf('Bearer %s', $this->serviceRoleKey),
            'apiKey' => $this->serviceRoleKey,
            'Content-Type' => 'application/json',
        ])->timeout(10)->post($endpoint, [
            'expiresIn' => $expiresIn,
        ]);

        if ($response->successful()) {
            $payload = $response->json();
            $signedUrl = $payload['signedUrl'] ?? $payload['signed_url'] ?? $payload['url'] ?? null;

            if ($signedUrl) {
                if (str_starts_with($signedUrl, '/storage/v1/')) {
                    $signedUrl = rtrim($this->supabaseUrl, '/') . $signedUrl;
                }

                return $signedUrl;
            }
        }

        Log::warning('Supabase temporary URL fallback used', [
            'path' => $path,
            'bucket' => $bucket,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return $this->getPublicUrl($relativePath);
    }

    public function generateSignedUploadUrl(string $bucket, string $filePath, int $expiresIn = 300): array
    {
        $endpoint = sprintf('%s/storage/v1/object/upload/sign/%s/%s', $this->supabaseUrl, $bucket, $filePath);
        Log::info('Supabase presign request', ['endpoint' => $endpoint, 'bucket' => $bucket, 'file_path' => $filePath, 'expiresIn' => $expiresIn]);

        $response = Http::withHeaders([
            'Authorization' => sprintf('Bearer %s', $this->serviceRoleKey),
            'apiKey' => $this->serviceRoleKey,
            'Content-Type' => 'application/json',
        ])->timeout(10)->post($endpoint, [
            'expiresIn' => $expiresIn,
        ]);

        $payload = $response->json();

        if (! in_array($response->status(), [200, 201], true)) {
            Log::error('Supabase presign request failed.', [
                'status' => $response->status(),
                'body' => $payload,
                'endpoint' => $endpoint,
                'bucket' => $bucket,
                'file_path' => $filePath,
            ]);

            throw new RuntimeException('Failed to generate Supabase upload URL. Response: ' . json_encode($payload));
        }

        Log::info('Supabase presign response', ['status' => $response->status(), 'body' => $payload, 'endpoint' => $endpoint]);

        // Normalize signed URL
        $signedUrl = $payload['signedUrl'] ?? $payload['signed_url'] ?? $payload['url'] ?? null;

        if (! $signedUrl) {
            Log::error('Supabase presign response missing signed URL.', [
                'payload' => $payload,
                'endpoint' => $endpoint,
                'bucket' => $bucket,
                'file_path' => $filePath,
            ]);

            throw new RuntimeException('Supabase upload URL response missing signed URL. Response: ' . json_encode($payload));
        }

        if (str_starts_with($signedUrl, '/storage/v1/')) {
            $signedUrl = rtrim($this->supabaseUrl, '/') . $signedUrl;
        }

        return [
            'signed_url' => $signedUrl,
            'bucket' => $bucket,
            'file_path' => $filePath,
        ];
    }
}
