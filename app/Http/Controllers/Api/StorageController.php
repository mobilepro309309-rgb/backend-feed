<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use RuntimeException;

class StorageController extends Controller
{
    private const DEFAULT_BUCKET = 'public-assets';
    private const ALLOWED_BUCKETS = ['public-assets', 'private-documents'];

    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const DOCUMENT_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const AUDIO_MIME_TYPES = [
        'audio/m4a',
        'audio/x-m4a',
        'audio/mp3',
        'audio/mpeg',
        'audio/aac',
        'audio/wav',
        'audio/ogg',
        'audio/3gpp',
        'audio/mp4',
        'audio/flac',
    ];

    public function presign(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'file_name' => ['required', 'string'],
            'file_type' => ['required', 'string'],
            'file_size' => ['required', 'numeric', 'max:10485760'],
            'bucket' => ['nullable', 'string', 'in:' . implode(',', self::ALLOWED_BUCKETS)],
        ])->validate();

        $bucket = $validated['bucket'] ?? self::DEFAULT_BUCKET;
        $fileType = strtolower(trim($validated['file_type']));
        $fileSize = (int) $validated['file_size'];

        if (in_array($fileType, self::IMAGE_MIME_TYPES, true)) {
            if ($fileSize > 5 * 1024 * 1024) {
                return Response::json(['success' => false, 'message' => 'Image upload size cannot exceed 5MB.'], 422);
            }
        } elseif (in_array($fileType, self::DOCUMENT_MIME_TYPES, true)) {
            if ($fileSize > 10 * 1024 * 1024) {
                return Response::json(['success' => false, 'message' => 'Document upload size cannot exceed 10MB.'], 422);
            }
        } elseif (in_array($fileType, self::AUDIO_MIME_TYPES, true)) {
            if ($fileSize > 15 * 1024 * 1024) {
                return Response::json(['success' => false, 'message' => 'Audio upload size cannot exceed 15MB.'], 422);
            }
        } else {
            return Response::json(['success' => false, 'message' => 'Unsupported file type.'], 422);
        }

        $user = $request->user();
        if (! $user) {
            return Response::json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $safeFilePath = $this->buildSafeFilePath($user->id, $validated['file_name']);

        try {
            $storageService = SupabaseStorageService::fromEnv();
            $signedPayload = $storageService->generateSignedUploadUrl($bucket, $safeFilePath, 300);
        } catch (RuntimeException $exception) {
            return Response::json(['success' => false, 'message' => $exception->getMessage()], 500);
        }

        $publicUrl = sprintf('%s/storage/v1/object/public/%s/%s', rtrim((string) env('SUPABASE_URL', ''), '/'), $bucket, $safeFilePath);

        return Response::json([
            'success' => true,
            'data' => array_merge($signedPayload, ['public_url' => $publicUrl]),
        ]);
    }

    private function buildSafeFilePath(int|string $userId, string $fileName): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $extension = $extension ? strtolower($extension) : 'bin';
        $uuid = Str::uuid()->toString();
        $year = date('Y');
        $month = date('m');

        return sprintf('uploads/users/%s/%s/%s/%s.%s', $userId, $year, $month, $uuid, $extension);
    }
}
