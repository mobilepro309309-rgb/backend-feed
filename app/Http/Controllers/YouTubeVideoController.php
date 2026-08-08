<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\YouTubeVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use Symfony\Component\HttpFoundation\Response;

class YouTubeVideoController extends Controller
{
    /**
     * Upload a video to YouTube and persist a record.
     */
    public function uploadVideo(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'video' => ['required', 'file', 'mimes:mp4,avi,mov,wmv,webm,mkv', 'max:512000'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'access_token' => ['nullable', 'string'],
            ]);

            $user = Auth::user();
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            $accessToken = $this->resolveAccessToken($request, $user);
            if (! $accessToken && ! env('YOUTUBE_REFRESH_TOKEN')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Google OAuth access token or refresh token is missing.',
                ], Response::HTTP_BAD_REQUEST);
            }

            if (! class_exists(Client::class)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Google API client package is not installed.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $client = $this->createGoogleClient();
            $youtube = new YouTube($client);
            $videoFile = $request->file('video');
            $videoPath = $videoFile->getRealPath();

            if (! $videoPath || ! is_file($videoPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The uploaded video file could not be read.',
                    'error' => 'The uploaded video file could not be read.',
                    'exception_details' => null,
                ], Response::HTTP_BAD_REQUEST);
            }

            try {
                $video = new Video();
                $videoSnippet = new VideoSnippet();
                $videoSnippet->setTitle($validated['title']);
                $videoSnippet->setDescription($validated['description'] ?? '');
                $video->setSnippet($videoSnippet);

                $videoStatus = new VideoStatus();
                $videoStatus->setPrivacyStatus('private');
                $video->setStatus($videoStatus);

                $client->setDefer(true);
                $insertRequest = $youtube->videos->insert('snippet,status', $video, [
                    'uploadType' => 'resumable',
                ]);

                $media = new MediaFileUpload(
                    $client,
                    $insertRequest,
                    $videoFile->getMimeType() ?: 'video/*',
                    null,
                    true,
                    5 * 1024 * 1024
                );
                $media->setFileSize(filesize($videoPath));

                $status = false;
                $handle = fopen($videoPath, 'rb');
                if ($handle === false) {
                    throw new \RuntimeException('Unable to open the uploaded video file.');
                }

                while (! $status) {
                    $chunk = fread($handle, 5 * 1024 * 1024);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $status = $media->nextChunk($chunk);
                }

                fclose($handle);

                if (! $status) {
                    throw new \RuntimeException('The YouTube upload did not complete.');
                }

                $uploadedVideo = $status;
                $youtubeVideoRecord = YouTubeVideo::create([
                    'user_id' => $user->id,
                    'youtube_video_id' => $uploadedVideo->getId(),
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'video_url' => $uploadedVideo->getId()
                        ? 'https://www.youtube.com/watch?v=' . $uploadedVideo->getId()
                        : null,
                    'status' => 'uploaded',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Video uploaded successfully.',
                    'video' => [
                        'id' => $youtubeVideoRecord->id,
                        'youtube_video_id' => $youtubeVideoRecord->youtube_video_id,
                        'title' => $youtubeVideoRecord->title,
                        'description' => $youtubeVideoRecord->description,
                        'video_url' => $youtubeVideoRecord->video_url,
                        'status' => $youtubeVideoRecord->status,
                    ],
                ], Response::HTTP_OK);
            } catch (\Throwable $e) {
                Log::error('YouTube Upload Error: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                    'title' => $validated['title'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'user_id' => $user->id ?? null,
                    'file_name' => $videoFile?->getClientOriginalName(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'YouTube upload failed.',
                    'error' => $e->getMessage(),
                    'exception_details' => $e->getTraceAsString(),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
                'error' => $exception->getMessage(),
                'exception_details' => $exception->getTraceAsString(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GoogleServiceException $exception) {
            Log::error('YouTube Upload Error: ' . $exception->getMessage(), [
                'trace' => $exception->getTraceAsString(),
                'errors' => $exception->getErrors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'YouTube upload failed.',
                'error' => $exception->getMessage(),
                'exception_details' => $exception->getTraceAsString(),
            ], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $exception) {
            Log::error('YouTube Upload Error: ' . $exception->getMessage(), [
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to upload the video right now.',
                'error' => $exception->getMessage(),
                'exception_details' => $exception->getTraceAsString(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected function createGoogleClient(): Client
    {
        try {
            $client = new Client();
            $client->setClientId((string) env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret((string) env('GOOGLE_CLIENT_SECRET'));
            $client->setRedirectUri((string) env('GOOGLE_REDIRECT_URI'));
            $client->setScopes([
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube',
            ]);
            $client->setAccessType('offline');
            $client->setApprovalPrompt('force');

            $refreshToken = trim(env('YOUTUBE_REFRESH_TOKEN'));
            if (empty($refreshToken)) {
                throw new \Exception('YOUTUBE_REFRESH_TOKEN is missing from environment.');
            }
            $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);

            if (isset($token['error']) || empty($token)) {
                throw new \Exception('Failed to obtain a fresh Google access token: ' . json_encode($token));
            }

            $client->setAccessToken($token);

            return $client;
        } catch (\Throwable $exception) {
            Log::error('Google client initialization failed: ' . $exception->getMessage(), [
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    protected function resolveAccessToken(Request $request, $user): ?string
    {
        $tokenFromRequest = $request->input('access_token');
        if (is_string($tokenFromRequest) && trim($tokenFromRequest) !== '') {
            return trim($tokenFromRequest);
        }

        $tokenFromHeader = $request->header('Authorization');
        if (is_string($tokenFromHeader) && str_starts_with($tokenFromHeader, 'Bearer ')) {
            return trim(substr($tokenFromHeader, 7));
        }

        if ($user) {
            foreach (['google_access_token', 'google_oauth_token', 'access_token'] as $property) {
                $value = $user->$property ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }
}
