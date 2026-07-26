<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SupabaseStorageService
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

    public function generateSignedUploadUrl(string $bucket, string $filePath, int $expiresIn = 300): array
    {
        $endpoint = sprintf('%s/storage/v1/object/upload/sign/%s/%s', $this->supabaseUrl, $bucket, $filePath);

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
