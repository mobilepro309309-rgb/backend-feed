<?php

namespace App\Http\Controllers;

use App\Events\NewNotificationEvent;
use App\Models\Notification;
use App\Models\NotificationUser;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\FirebaseService;
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

            // Disabled intentionally: no automatic security login notification is created when a device token is stored.
            // This prevents database rows from being inserted into notifications / notification_user for new device registrations.
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

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(0, (int) $request->query('limit', 0));

        $baseQuery = NotificationUser::where('user_id', $user->id);
        $totalNotifications = $baseQuery->count();

        $query = NotificationUser::where('user_id', $user->id)
            ->with(['notification'])
            ->orderByDesc('created_at');

        if ($limit > 0) {
            $query->offset(($page - 1) * $limit)->limit($limit);
        }

        $items = $query->get()
            ->map(function (NotificationUser $delivery) {
                $notification = $delivery->notification;
                $payload = $notification?->data ?? [];
                $rawType = data_get($payload, 'type') ?? $notification?->type ?? 'general';
                $normalizedType = match ($rawType) {
                    'new_question' => 'multiple_choice',
                    'new_true_false' => 'true_false',
                    'new_find_the_bug' => 'find_the_bug',
                    'new_daily_challenge' => 'daily_challenge',
                    'new_comparison' => 'comparison_card',
                    'new_cloud_capsule' => 'post',
                    'new_live_duel' => 'live_duel',
                    'new_video' => 'video',
                    'new_comment' => 'comment',
                    'comment' => 'comment',
                    'comments' => 'comment',
                    default => $rawType,
                };

                $isQuizType = in_array($normalizedType, ['multiple_choice', 'true_false', 'find_the_bug', 'daily_challenge', 'comparison_card', 'cheat_sheet_flip', 'live_duel', 'question', 'quiz'], true);
                $isVideoType = in_array($normalizedType, ['video', 'videos', 'new_video'], true);
                $isFriendRequest = in_array($normalizedType, ['friend_request', 'friend_request_accepted'], true);
                $isCommentType = $normalizedType === 'comment';
                $isChatType = $normalizedType === 'chat_message' || data_get($payload, 'type') === 'chat_message' || data_get($payload, 'targetType') === 'chat' || data_get($payload, 'target_type') === 'chat';
                $questionId = data_get($payload, 'question_id') ?? data_get($payload, 'quiz_id') ?? data_get($payload, 'challenge_id') ?? data_get($payload, 'comparison_id') ?? data_get($payload, 'capsule_id') ?? data_get($payload, 'id');
                $targetId = data_get($payload, 'target_id') ?? data_get($payload, 'targetId') ?? data_get($payload, 'post_id') ?? data_get($payload, 'postId') ?? data_get($payload, 'chat_id') ?? data_get($payload, 'feed_id') ?? $questionId;
                $targetType = data_get($payload, 'target_type') ?? data_get($payload, 'targetType') ?? ($isChatType ? 'chat' : ($isVideoType ? 'videos' : ($isQuizType ? 'quiz' : ($isFriendRequest ? 'friends' : 'post'))));
                $actionType = $isCommentType ? 'comments' : ($isFriendRequest ? 'friends' : ($isVideoType ? 'videos' : ($isChatType ? 'chat' : ($isQuizType ? 'quiz' : 'post'))));
                $commentId = data_get($payload, 'comment_id') ?? data_get($payload, 'commentId') ?? null;
                $postId = data_get($payload, 'post_id') ?? data_get($payload, 'postId') ?? $targetId;

                return [
                    'id' => 'notif-' . $notification?->id,
                    'notificationId' => $notification?->id,
                    'notification_id' => $notification?->id,
                    'title' => $notification?->title ?? 'إشعار جديد',
                    'body' => $notification?->body ?? '',
                    'type' => $normalizedType,
                    'actionType' => $actionType,
                    'questionSnippet' => $questionId ? 'سؤال #' . $questionId : null,
                    'targetId' => $targetId,
                    'targetType' => $targetType,
                    'post_id' => $postId,
                    'comment_id' => $commentId,
                    'chat_id' => data_get($payload, 'chat_id') ?? data_get($payload, 'chatId') ?? null,
                    'sender_id' => data_get($payload, 'sender_id') ?? data_get($payload, 'senderId') ?? null,
                    'sender_name' => data_get($payload, 'sender_name') ?? data_get($payload, 'senderName') ?? null,
                    'sender_avatar' => data_get($payload, 'sender_avatar') ?? data_get($payload, 'senderAvatar') ?? null,
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
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalNotifications,
                'count' => $items->count(),
                'hasMore' => $limit > 0 ? ($page * $limit < $totalNotifications) : false,
            ],
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $payload = $request->all();
        $notificationIds = [];

        if (! empty($payload['notification_id'])) {
            $notificationIds[] = (int) filter_var($payload['notification_id'], FILTER_SANITIZE_NUMBER_INT);
        }

        if (! empty($payload['ids']) && is_array($payload['ids'])) {
            foreach ($payload['ids'] as $idValue) {
                if ($idValue === null || $idValue === '') {
                    continue;
                }
                $notificationIds[] = (int) filter_var($idValue, FILTER_SANITIZE_NUMBER_INT);
            }
        }

        $notificationIds = array_values(array_unique(array_filter($notificationIds, fn ($id) => $id > 0)));

        if (empty($notificationIds)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid notification IDs provided.',
            ], 422);
        }

        $updated = NotificationUser::where('user_id', $user->id)
            ->whereIn('notification_id', $notificationIds)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'updated' => $updated,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        Log::info('[NotificationController@send] incoming broadcast payload', [
            'request' => $request->all(),
            'headers' => [
                'authorization' => $request->header('authorization'),
            ],
        ]);

        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'title' => 'required|string',
            'body' => 'required|string',
            'type' => 'nullable|string',
            'data' => 'nullable|array',
            'target_role' => 'nullable|string',
            'target_grade' => 'nullable|string',
            'school_grade' => 'nullable|string',
            'audience' => 'nullable|string',
        ]);

        try {
            $authUser = $request->user();
            $explicitUserId = isset($validated['user_id']) ? (int) $validated['user_id'] : null;
            $targetRole = $this->normalizeTargetRole($validated['target_role'] ?? $validated['audience'] ?? null);
            $targetGrade = $this->normalizeTargetGrade($validated['target_grade'] ?? $validated['school_grade'] ?? null);

            if ($authUser && strtolower((string) $authUser->role) === 'teacher') {
                $authorizedGrades = $authUser->teacherScopes()
                    ->pluck('school_grade')
                    ->map(fn ($grade) => $this->normalizeTargetGrade((string) $grade))
                    ->filter(fn ($grade) => $grade !== null && $grade !== '')
                    ->unique()
                    ->values()
                    ->all();

                if (empty($authorizedGrades)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'لا توجد صلاحية لتحديد الصف للمدرس الحالي.',
                    ], 403);
                }

                if ($targetGrade === null || strtolower((string) $targetGrade) === 'all') {
                    $targetGrade = $authorizedGrades[0];
                } elseif (! in_array($targetGrade, $authorizedGrades, true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'لا يمكنك إرسال إشعار إلى صف خارج صلاحياتك التعليمية.',
                        'authorized_grades' => $authorizedGrades,
                    ], 403);
                }

                $validated['target_grade'] = $targetGrade;
                $validated['school_grade'] = $targetGrade;
            }

            Log::info('[NotificationController@send] normalized filter values', [
                'explicit_user_id' => $explicitUserId,
                'target_role' => $targetRole,
                'target_grade' => $targetGrade,
                'auth_user_role' => $authUser?->role,
            ]);

            $data = $validated['data'] ?? [];
            if (! empty($validated['type'])) {
                $data['type'] = $validated['type'];
            }

            if ($targetRole !== null) {
                $data['target_role'] = $targetRole;
            }
            if ($targetGrade !== null) {
                $data['target_grade'] = $targetGrade;
            }

            if ($explicitUserId !== null) {
                $user = User::findOrFail($explicitUserId);
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
                    'count' => 1,
                    'user_ids' => [$user->id],
                    'data' => $result,
                ], $allDelivered ? 200 : 207);
            }

            $query = User::query();

            if ($targetRole !== null && $targetRole !== 'all') {
                $query->where(function ($roleQuery) use ($targetRole) {
                    $roleQuery->whereRaw('LOWER(role) = ?', [mb_strtolower($targetRole)])
                        ->orWhereRaw('LOWER(role) = ?', [mb_strtolower(str_replace(['-', '_'], ' ', $targetRole))]);
                });
            }

            $recipients = $query->get()->filter(function (User $user) use ($targetRole, $targetGrade) {
                if ($targetRole !== null && $targetRole !== 'all' && ! $this->roleMatches($user->role, $targetRole)) {
                    return false;
                }

                if ($targetGrade !== null && ! $this->gradeMatches($user->school_grade, $targetGrade)) {
                    return false;
                }

                return true;
            })->values();

            if ($recipients->isEmpty()) {
                $friendlyMessage = 'لا يوجد مستخدمون مطابّقون لهذه الفئة لإرسال الإشعار إليهم';

                Log::warning('[NotificationController@send] no recipients matched broadcast filters', [
                    'target_role' => $targetRole,
                    'target_grade' => $targetGrade,
                    'audience' => $validated['audience'] ?? null,
                    'request' => $request->all(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $friendlyMessage,
                    'count' => 0,
                    'user_ids' => [],
                ], 200);
            }

            $broadcastData = array_merge($data, [
                'broadcast' => true,
                'audience' => $targetRole ?? 'all',
                'target_role' => $targetRole ?? 'all',
                'target_grade' => $targetGrade ?? 'all',
                'grade' => $targetGrade ?? 'all',
                'source' => 'admin_broadcast',
            ]);

            $notification = Notification::create([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'type' => $broadcastData['type'] ?? 'broadcast',
                'data' => $broadcastData,
            ]);

            $firebaseService = app(FirebaseService::class);
            $broadcastDelivery = [];

            foreach ($recipients as $recipient) {
                $deviceTokens = UserDevice::where('user_id', $recipient->id)
                    ->pluck('fcm_token')
                    ->filter(fn ($token) => is_string($token) && trim($token) !== '')
                    ->values()
                    ->all();

                foreach ($deviceTokens as $deviceToken) {
                    try {
                        $firebaseService->sendNotification(
                            $deviceToken,
                            $validated['title'],
                            $validated['body'],
                            $broadcastData
                        );

                        $broadcastDelivery[] = [
                            'user_id' => $recipient->id,
                            'device_token' => $deviceToken,
                            'channel' => 'private-user.' . $recipient->id,
                            'delivery' => 'fcm',
                        ];
                    } catch (\Throwable $e) {
                        Log::warning('[NotificationController@send] FCM broadcast delivery failed', [
                            'user_id' => $recipient->id,
                            'error' => $e->getMessage(),
                        ]);

                        $broadcastDelivery[] = [
                            'user_id' => $recipient->id,
                            'device_token' => $deviceToken,
                            'channel' => 'private-user.' . $recipient->id,
                            'delivery' => 'fcm_failed',
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                try {
                    broadcast(new NewNotificationEvent($notification, (int) $recipient->id));
                    $broadcastDelivery[] = [
                        'user_id' => $recipient->id,
                        'channel' => 'private-user.' . $recipient->id,
                        'delivery' => 'reverb',
                    ];
                } catch (\Throwable $e) {
                    Log::warning('[NotificationController@send] Reverb broadcast failed', [
                        'user_id' => $recipient->id,
                        'error' => $e->getMessage(),
                    ]);

                    $broadcastDelivery[] = [
                        'user_id' => $recipient->id,
                        'channel' => 'private-user.' . $recipient->id,
                        'delivery' => 'reverb_failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Broadcast notification created successfully.',
                'count' => 0,
                'user_ids' => [],
                'notification_id' => $notification->id,
                'data' => [
                    'notification' => $notification,
                    'target_role' => $targetRole,
                    'target_grade' => $targetGrade,
                    'recipient_count' => $recipients->count(),
                    'broadcast_delivery' => $broadcastDelivery,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Notification dispatch failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Notification failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function normalizeTargetRole(?string $value): ?string
    {
        if ($value === null || trim((string) $value) === '' || strtolower(trim((string) $value)) === 'all') {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        $normalized = str_replace(['-', '_'], ' ', $normalized);

        $map = [
            'student' => 'user',
            'students' => 'user',
            'user' => 'user',
            'users' => 'user',
            'teacher' => 'teacher',
            'teachers' => 'teacher',
            'main admin' => 'main-admin',
            'main-admin' => 'main-admin',
            'admin' => 'admin',
            'reply questions admin' => 'reply_questions_admin',
            'question post admin' => 'question_post_admin',
            'financial admin' => 'financial_admin',
            'technical support admin' => 'technical_support_admin',
        ];

        return $map[$normalized] ?? preg_replace('/\s+/', '-', $normalized);
    }

    protected function normalizeTargetGrade(?string $value): ?string
    {
        if ($value === null || trim((string) $value) === '' || strtolower(trim((string) $value)) === 'all') {
            return null;
        }

        $normalized = User::normalizeSchoolGradeValue($value);

        return $normalized !== null && $normalized !== '' ? (string) $normalized : trim((string) $value);
    }

    protected function roleMatches(?string $storedRole, string $targetRole): bool
    {
        if ($storedRole === null || $storedRole === '') {
            return false;
        }

        $normalizedStored = strtolower(trim((string) $storedRole));
        $normalizedStored = str_replace(['-', '_'], ' ', $normalizedStored);
        $normalizedTarget = strtolower(trim((string) $targetRole));
        $normalizedTarget = str_replace(['-', '_'], ' ', $normalizedTarget);

        return $normalizedStored === $normalizedTarget || $normalizedStored === str_replace(' ', '-', $normalizedTarget);
    }

    protected function gradeMatches(?string $storedGrade, string $targetGrade): bool
    {
        if ($storedGrade === null || $storedGrade === '') {
            return false;
        }

        $storedNormalized = User::normalizeSchoolGradeValue($storedGrade);
        $targetNormalized = User::normalizeSchoolGradeValue($targetGrade);

        if ($storedNormalized === null || $targetNormalized === null) {
            return strtolower(trim((string) $storedGrade)) === strtolower(trim((string) $targetGrade));
        }

        return $storedNormalized === $targetNormalized;
    }
}
