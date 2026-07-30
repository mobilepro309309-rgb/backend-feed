<?php

namespace App\Http\Controllers;

use App\Models\NotificationUser;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function __construct(protected NotificationService $notificationService)
    {
    }

    public function storeToken(Request $request): JsonResponse
    {
        // Log request basics for debugging
        Log::info('[NotificationController@storeToken] incoming request', [
            'ip' => $request->ip(),
            'headers' => [
                'authorization' => $request->header('authorization'),
                'content-type' => $request->header('content-type'),
            ],
            'body' => $request->all(),
        ]);

        try {
            $validated = $request->validate([
                'fcm_token' => 'required|string',
                'device_type' => 'nullable|string|in:android,ios,web',
            ]);

            $user = $request->user();

            if (! $user) {
                Log::warning('[NotificationController@storeToken] unauthenticated request', ['body' => $validated]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            try {
$deviceToken = $validated['fcm_token'];
            $existingDevice = UserDevice::where('user_id', $user->id)
                ->where('fcm_token', $deviceToken)
                ->first();

            $device = $this->notificationService->registerToken(
                $user,
                $deviceToken,
                $validated['device_type'] ?? null
            );

            if (! $existingDevice) {
                $this->notificationService->sendNotification(
                    $user,
                    'تنبيه أمني',
                    'تم تسجيل الدخول إلى حسابك من هاتف جديد. هل أنت هذا الشخص؟',
                    [
                        'type' => 'security_login_alert',
                        'source' => 'new_device_login',
                        'user_id' => $user->id,
                    ],
                    $deviceToken
                );
            }

            Log::info('[NotificationController@storeToken] device registered', ['user_id' => $user->id, 'device_id' => $device->id ?? null, 'fcm_token' => $deviceToken]);

                return response()->json([
                    'success' => true,
                    'message' => 'Device token stored successfully.',
                    'data' => $device,
                ]);
            } catch (\Throwable $e) {
                Log::error('[NotificationController@storeToken] registerToken failed', ['error' => $e->getMessage(), 'user_id' => $user->id, 'body' => $validated]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to store device token.',
                    'error' => $e->getMessage(),
                ], 500);
            }
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::warning('[NotificationController@storeToken] validation failed', ['errors' => $ve->errors(), 'body' => $request->all()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $ve->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[NotificationController@storeToken] unexpected error', ['error' => $e->getMessage(), 'body' => $request->all()]);
            return response()->json([
                'success' => false,
                'message' => 'Server error while storing device token.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $items = NotificationUser::where('user_id', $user->id)
            ->with(['notification'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (NotificationUser $delivery) {
                $notification = $delivery->notification;
                $payload = $notification?->data ?? [];
                $rawType = $payload['type'] ?? $notification?->type ?? 'general';
                $normalizedType = match ($rawType) {
                    'new_question' => 'multiple_choice',
                    'new_true_false' => 'true_false',
                    'new_find_the_bug' => 'find_the_bug',
                    'new_daily_challenge' => 'daily_challenge',
                    'new_comparison' => 'comparison_card',
                    'new_cloud_capsule' => 'post',
                    'new_live_duel' => 'live_duel',
                    'new_video' => 'video',
                    'friend_request' => 'friend_request',
                    'friend_request_accepted' => 'friend_request_accepted',
                    default => $rawType,
                };

                $isQuizType = in_array($normalizedType, ['multiple_choice', 'true_false', 'find_the_bug', 'daily_challenge', 'comparison_card', 'cheat_sheet_flip', 'live_duel', 'question', 'quiz'], true);
                $isVideoType = in_array($normalizedType, ['video', 'videos', 'new_video'], true);
                $isFriendRequest = $normalizedType === 'friend_request' || $normalizedType === 'friend_request_accepted' || data_get($payload, 'type') === 'friend_request' || data_get($payload, 'type') === 'friend_request_accepted';
                $questionId = data_get($payload, 'question_id') ?? data_get($payload, 'quiz_id') ?? data_get($payload, 'challenge_id') ?? data_get($payload, 'comparison_id') ?? data_get($payload, 'capsule_id') ?? data_get($payload, 'id');
                $isChatType = $normalizedType === 'chat_message' || data_get($payload, 'type') === 'chat_message' || data_get($payload, 'targetType') === 'chat' || data_get($payload, 'target_type') === 'chat';
                $targetId = data_get($payload, 'target_id') ?? data_get($payload, 'targetId') ?? data_get($payload, 'chat_id') ?? data_get($payload, 'target_id') ?? data_get($payload, 'feed_id') ?? $questionId;
                $targetType = data_get($payload, 'target_type') ?? data_get($payload, 'targetType') ?? ($isChatType ? 'chat' : ($isVideoType ? 'videos' : ($isQuizType ? 'quiz' : ($isFriendRequest ? 'friends' : 'post'))));

                return [
                    'id' => 'notif-' . $notification?->id,
                    'title' => $notification?->title ?? 'إشعار جديد',
                    'body' => $notification?->body ?? '',
                    'type' => $normalizedType,
                    'actionType' => $isChatType ? 'chat' : ($isVideoType ? 'videos' : ($isQuizType ? 'quiz' : ($isFriendRequest ? 'friends' : 'post'))),
                    'questionSnippet' => $questionId ? 'سؤال #' . $questionId : null,
                    'targetId' => $targetId,
                    'targetType' => $targetType,
                    'chat_id' => data_get($payload, 'chat_id') ?? null,
                    'sender_id' => data_get($payload, 'sender_id') ?? null,
                    'sender_name' => data_get($payload, 'sender_name') ?? null,
                    'sender_avatar' => data_get($payload, 'sender_avatar') ?? null,
                    'targetPostId' => $targetId,
                    'unread' => ! $delivery->read_at,
                    'accent' => $isQuizType ? '#3B82F6' : '#10B981',
                    'author' => 'المنصة',
                    'time' => optional($notification?->created_at)->diffForHumans() ?? 'الآن',
                    'created_at' => optional($notification?->created_at)?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'title' => 'required|string',
            'body' => 'required|string',
            'type' => 'nullable|string',
            'data' => 'nullable|array',
        ]);

        try {
            $user = null;

            if (! empty($validated['user_id'])) {
                $user = User::findOrFail($validated['user_id']);
            } else {
                $user = $request->user();
            }

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not provided.',
                ], 422);
            }

            $data = $validated['data'] ?? [];

            if (! empty($validated['type'])) {
                $data['type'] = $validated['type'];
            }

            $result = $this->notificationService->sendNotification(
                $user,
                $validated['title'],
                $validated['body'],
                $data
            );

            $firebaseResults = $result['firebase'] ?? [];
            $allDelivered = collect($firebaseResults)->every(function ($item) {
                return isset($item['result']['success']) && $item['result']['success'] === true;
            });

            return response()->json([
                'success' => $allDelivered,
                'message' => $allDelivered ? 'Notification processed successfully.' : 'Notification processed with partial or full delivery failure.',
                'data' => $result,
            ], $allDelivered ? 200 : 207);
        } catch (\Throwable $e) {
            Log::error('Notification dispatch failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Notification failed.',
            ], 500);
        }
    }
}
