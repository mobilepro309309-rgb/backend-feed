<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Contracts\StorageServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class StorageController extends Controller
{
    private StorageServiceInterface $storageService;

    public function __construct(StorageServiceInterface $storageService)
    {
        $this->storageService = $storageService;
    }
    private const DEFAULT_BUCKET = 'public-assets';
    private const ALLOWED_BUCKETS = ['public-assets', 'private-documents'];

    private const GLOBAL_ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'txt', 'mp3', 'm4a', 'mp4', 'ppt', 'pptx', 'xls', 'xlsx', 'zip',
    ];

    private const STRICT_BLACKLIST_EXTENSIONS = [
        'exe', 'bat', 'sh', 'php', 'phtml', 'phar', 'js', 'html', 'htm', 'svg', 'apk', 'vbs', 'jar', 'cmd', 'cgi', 'pl',
    ];

    private const CATEGORY_ALLOWED_EXTENSIONS = [
        'avatars' => ['jpg', 'jpeg', 'png', 'webp'],
        'comments' => ['jpg', 'jpeg', 'png', 'webp', 'mp4'],
        'posts' => ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'pdf', 'doc', 'docx'],
        'chat' => ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mp3', 'm4a', 'pdf', 'doc', 'docx', 'txt', 'zip'],
        'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip'],
    ];

    private const EXTENSION_MIME_MAP = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'doc' => [
            'application/msword',
            'application/vnd.ms-word',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
        ],
        'txt' => ['text/plain'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'm4a' => ['audio/m4a', 'audio/mp4'],
        'mp4' => ['video/mp4', 'audio/mp4'],
        'zip' => ['application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/octet-stream'],
    ];

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
            'file_size' => ['required', 'numeric'],
            'bucket' => ['nullable', 'string', 'in:' . implode(',', self::ALLOWED_BUCKETS)],
            'category' => ['required', 'string', 'in:avatars,comments,posts,chat,documents'],
        ])->validate();

        $bucket = $validated['bucket'] ?? self::DEFAULT_BUCKET;
        $category = $validated['category'];
        $fileType = strtolower(trim($validated['file_type']));
        $fileSize = (int) $validated['file_size'];
        $fileName = trim($validated['file_name']);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        Log::info('[StorageController] presign request', [
            'user_id' => $request->user()?->id ?? null,
            'category' => $category,
            'bucket' => $bucket,
            'file_name' => $fileName,
            'file_type' => $fileType,
            'file_size' => $fileSize,
            'extension' => $extension,
            'ip' => $request->ip(),
        ]);

        if ($extension === '' || ! in_array($extension, self::GLOBAL_ALLOWED_EXTENSIONS, true)) {
            Log::warning('[Security Alert] Unauthorized file upload attempt rejected', [
                'user_id' => $request->user()?->id ?? null,
                'ip' => $request->ip(),
                'file_name' => $fileName,
                'file_type' => $fileType,
                'extension' => $extension,
                'category' => $category,
            ]);

            return Response::json([
                'status' => 'error',
                'message' => 'نوع الملف أو الامتداد غير مسموح به لأسباب أمنية.',
                'error_code' => 'INVALID_FILE_TYPE',
            ], 422);
        }

        if (in_array($extension, self::STRICT_BLACKLIST_EXTENSIONS, true)) {
            Log::warning('[Security Alert] Unauthorized file upload attempt rejected', [
                'user_id' => $request->user()?->id ?? null,
                'ip' => $request->ip(),
                'file_name' => $fileName,
                'file_type' => $fileType,
                'extension' => $extension,
                'category' => $category,
            ]);

            return Response::json([
                'status' => 'error',
                'message' => 'نوع الملف أو الامتداد غير مسموح به لأسباب أمنية.',
                'error_code' => 'INVALID_FILE_TYPE',
            ], 422);
        }

        if (! isset(self::CATEGORY_ALLOWED_EXTENSIONS[$category]) || ! in_array($extension, self::CATEGORY_ALLOWED_EXTENSIONS[$category], true)) {
            Log::warning('[Security Alert] Unauthorized file upload attempt rejected', [
                'user_id' => $request->user()?->id ?? null,
                'ip' => $request->ip(),
                'file_name' => $fileName,
                'file_type' => $fileType,
                'extension' => $extension,
                'category' => $category,
            ]);

            return Response::json([
                'status' => 'error',
                'message' => 'نوع الملف أو الامتداد غير مسموح به لأسباب أمنية.',
                'error_code' => 'INVALID_FILE_TYPE',
            ], 422);
        }

        $allowedMimeTypes = self::EXTENSION_MIME_MAP[$extension] ?? [];
        $normalizedFileType = strtolower($fileType);
        $mimeMatches = false;
        foreach ($allowedMimeTypes as $allowedMimeType) {
            if (strpos($normalizedFileType, $allowedMimeType) === 0) {
                $mimeMatches = true;
                break;
            }
        }

        if (! $mimeMatches) {
            Log::warning('[Security Alert] Unauthorized file upload attempt rejected', [
                'user_id' => $request->user()?->id ?? null,
                'ip' => $request->ip(),
                'file_name' => $fileName,
                'file_type' => $fileType,
                'extension' => $extension,
                'category' => $category,
            ]);

            return Response::json([
                'status' => 'error',
                'message' => 'نوع الملف أو الامتداد غير مسموح به لأسباب أمنية.',
                'error_code' => 'INVALID_FILE_TYPE',
            ], 422);
        }

        // Strict PDF MIME + Extension pairing
        if ($extension === 'pdf' && $fileType !== 'application/pdf') {
            Log::warning('[PDF Security] Invalid PDF upload attempt rejected', [
                'user_id' => $request->user()?->id ?? null,
                'file_name' => $fileName,
                'file_type' => $fileType,
                'file_size' => $fileSize,
                'category' => $category,
            ]);

            return Response::json([
                'status' => 'error',
                'message' => 'نوع الملف أو الامتداد غير مسموح به لأسباب أمنية.',
                'error_code' => 'INVALID_FILE_TYPE',
            ], 422);
        }

        // Determine size limits by type
        $docLike = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip'];
        $imageLike = ['jpg', 'jpeg', 'png', 'webp'];
        $mediaLike = ['mp3', 'm4a', 'mp4'];

        if (in_array($extension, $docLike, true)) {
            $typeMax = 36700160; // 35MB approx as requested (36,700,160)
        } elseif (in_array($extension, $imageLike, true)) {
            $typeMax = 10485760; // 10,485,760 requested (10MB approx)
        } elseif (in_array($extension, $mediaLike, true)) {
            $typeMax = 52428800; // 52,428,800 requested (50MB approx)
        } else {
            $typeMax = 10485760; // default 10MB
        }

        if ($fileSize > $typeMax) {
            if ($extension === 'pdf') {
                Log::warning('[PDF Security] Invalid PDF upload attempt rejected', [
                    'user_id' => $request->user()?->id ?? null,
                    'file_name' => $fileName,
                    'file_type' => $fileType,
                    'file_size' => $fileSize,
                    'category' => $category,
                ]);
            } else {
                Log::warning('[Security Alert] File size exceeds type limit', [
                    'user_id' => $request->user()?->id ?? null,
                    'file_name' => $fileName,
                    'file_type' => $fileType,
                    'file_size' => $fileSize,
                    'category' => $category,
                    'type_max' => $typeMax,
                ]);
            }

            return Response::json([
                'status' => 'error',
                'message' => 'حجم الملف يتجاوز الحد المسموح به لهذا النوع.',
                'error_code' => 'FILE_TOO_LARGE',
            ], 422);
        }

        // Category-specific acceptance (already validated extension membership above)
        if (! isset(self::CATEGORY_ALLOWED_EXTENSIONS[$category])) {
            return Response::json(['status' => 'error', 'message' => 'Invalid category.'], 422);
        }

        $user = $request->user();
        if (! $user) {
            return Response::json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        $safeFilePath = $this->buildSafeFilePath($category, $user->id, $validated['file_name']);

        try {
            $encoded = sprintf('%s::%s', $bucket, $safeFilePath);
            $signedPayload = $this->storageService->generatePresignedUrl($encoded, $fileType, $category, $fileSize);
        } catch (RuntimeException $exception) {
            Log::error('[StorageController] presign failed', ['error' => $exception->getMessage()]);
            return Response::json(['status' => 'error', 'message' => $exception->getMessage()], 500);
        }

        $publicUrl = $this->storageService->getPublicUrl($safeFilePath);

        $response = [
            'status' => 'success',
            'data' => [
                'signed_url' => $signedPayload['signed_url'] ?? ($signedPayload['signedUrl'] ?? null),
                'public_url' => $publicUrl,
                'bucket' => $bucket,
                'file_path' => $safeFilePath,
                'category' => $category,
            ],
        ];

        Log::info('[StorageController] presign response prepared', ['user_id' => $user->id, 'file_path' => $safeFilePath, 'bucket' => $bucket, 'category' => $category]);

        return Response::json($response);
    }

    public function getPrivateFileUrl(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return Response::json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        $validated = Validator::make($request->all(), [
            'file_path' => ['required', 'string'],
            'resource_type' => ['nullable', 'string'],
            'resource_id' => ['nullable', 'integer'],
        ])->validate();

        $filePath = trim($validated['file_path']);
        $resourceType = $validated['resource_type'] ?? null;
        $resourceId = $validated['resource_id'] ?? null;

        if ($resourceType && $resourceId) {
            if ($resourceType === 'document' && class_exists(\App\Models\Document::class)) {
                $document = \App\Models\Document::query()->find($resourceId);
                if ($document && (int) $document->user_id !== (int) $user->id) {
                    return Response::json(['status' => 'error', 'message' => 'Forbidden.'], 403);
                }
            }

            if ($resourceType === 'lesson' && class_exists(\App\Models\Lesson::class)) {
                $lesson = \App\Models\Lesson::query()->find($resourceId);
                if ($lesson && method_exists($lesson, 'user') && (int) $lesson->user_id !== (int) $user->id) {
                    return Response::json(['status' => 'error', 'message' => 'Forbidden.'], 403);
                }
            }
        }

        $temporaryUrl = $this->storageService->getTemporaryUrl($filePath, 15);

        return Response::json([
            'status' => 'success',
            'data' => [
                'file_path' => $filePath,
                'temporary_url' => $temporaryUrl,
                'expires_in' => 15 * 60,
            ],
        ]);
    }

    private function buildSafeFilePath(string $category, int|string $userId, string $fileName): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $extension = $extension ? strtolower($extension) : 'bin';
        $uuid = Str::uuid()->toString();
        $year = date('Y');
        $month = date('m');

        return sprintf('uploads/%s/%s/%s/%s/%s.%s', $category, $userId, $year, $month, $uuid, $extension);
    }
}
