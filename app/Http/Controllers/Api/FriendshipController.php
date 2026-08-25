<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log};

use App\Http\Controllers\Controller;
use App\Http\Controllers\ChatController as BaseChatController;
use App\Models\{Chat, ChatParticipant, Friendship, User};
use App\Services\NotificationService;

class FriendshipController extends Controller
{
    public function __construct(protected NotificationService $notificationService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $type = $request->query('type', 'colleagues');
        if (! in_array($type, ['colleagues', 'nearby', 'teachers'], true)) {
            return response()->json(['message' => 'Invalid type parameter.'], 422);
        }

        if ($type === 'teachers') {
            $teacherFriendships = Friendship::query()
                ->where('status', 'accepted')
                ->where('teacher', 1)
                ->where(function ($query) use ($user) {
                    $query->where('sender_id', $user->id)
                        ->orWhere('receiver_id', $user->id);
                })
                ->get();

            $teacherMeta = $teacherFriendships->mapWithKeys(function (Friendship $friendship) use ($user) {
                $teacherId = $friendship->sender_id === $user->id
                    ? $friendship->receiver_id
                    : $friendship->sender_id;

                return [$teacherId => $friendship];
            });

            $teachers = User::with('address')
                ->whereIn('id', $teacherMeta->keys()->all())
                ->get(['id', 'name', 'email', 'phone', 'school_grade'])
                ->map(function (User $teacher) use ($teacherMeta, $user) {
                    $friendship = $teacherMeta[$teacher->id];
                    $chatId = $friendship->chat_id ?? $this->ensureFriendshipChat($friendship);

                    return array_merge($teacher->toArray(), [
                        'friendship_status' => 'accepted',
                        'chat_id' => $chatId,
                        'status' => 'accepted',
                        'teacher' => 1,
                    ]);
                });

            return response()->json([
                'type' => 'teachers',
                'data' => $teachers,
            ], 200);
        }

        if ($type === 'nearby') {
            $search = trim((string) $request->query('search', ''));
            $scopeStageId = $user->stage_id;
            $scopeGradeId = $user->grade_id;
            $scopeTrackId = $user->track_id;

            Log::info('[NearbyColleagues] scope resolved', [
                'user_id' => $user->id,
                'role' => $user->role,
                'stage_id' => $scopeStageId,
                'grade_id' => $scopeGradeId,
                'track_id' => $scopeTrackId,
                'school_grade' => $user->school_grade,
                'grade_relation_id' => $user->grade?->id,
                'grade_relation_stage_id' => $user->grade?->stage_id,
            ]);

            if ($scopeStageId === null || $scopeGradeId === null) {
                Log::warning('[NearbyColleagues] incomplete academic scope, returning empty list', [
                    'user_id' => $user->id,
                    'stage_id' => $scopeStageId,
                    'grade_id' => $scopeGradeId,
                    'track_id' => $scopeTrackId,
                ]);
                return response()->json([
                    'type' => 'nearby',
                    'data' => [],
                ], 200);
            }

            $friendships = Friendship::query()
                ->where(function ($query) use ($user) {
                    $query->where('sender_id', $user->id)
                        ->orWhere('receiver_id', $user->id);
                })
                ->whereIn('status', ['accepted', 'blocked', 'pending'])
                ->get();

            $blockedIds = $friendships
                ->where('status', 'blocked')
                ->map(static function (Friendship $friendship) use ($user) {
                    return $friendship->sender_id === $user->id
                        ? $friendship->receiver_id
                        : $friendship->sender_id;
                })
                ->unique()
                ->values()
                ->all();

            $acceptedIds = $friendships
                ->where('status', 'accepted')
                ->map(static function (Friendship $friendship) use ($user) {
                    return $friendship->sender_id === $user->id
                        ? $friendship->receiver_id
                        : $friendship->sender_id;
                })
                ->unique()
                ->values()
                ->all();

            $pendingSentIds = $friendships
                ->where('status', 'pending')
                ->where('sender_id', $user->id)
                ->pluck('receiver_id')
                ->all();

            $nearbyQuery = User::query()
                ->where('id', '!=', $user->id)
                ->where('role', 'user')
                ->where('stage_id', $scopeStageId)
                ->where('grade_id', $scopeGradeId)
                ->when($scopeTrackId !== null, fn ($query) => $query->where('track_id', $scopeTrackId))
                ->when($scopeTrackId === null, fn ($query) => $query->whereNull('track_id'))
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($searchQuery) use ($search) {
                        $searchQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('id', is_numeric($search) ? (int) $search : 0);
                    });
                });

            Log::info('[NearbyColleagues] query prepared', [
                'user_id' => $user->id,
                'scope' => [
                    'stage_id' => $scopeStageId,
                    'grade_id' => $scopeGradeId,
                    'track_id' => $scopeTrackId,
                ],
                'search' => $search !== '' ? $search : null,
                'sql' => $nearbyQuery->toSql(),
                'bindings' => $nearbyQuery->getBindings(),
            ]);

            $nearbyUsers = $nearbyQuery
                ->when(count($blockedIds) > 0, function ($query) use ($blockedIds) {
                    $query->whereNotIn('id', $blockedIds);
                })
                ->get(['id', 'name', 'email', 'phone', 'school_grade', 'stage_id', 'grade_id', 'track_id'])
                ->sortBy(fn (User $friend): string => (string) $friend->name)
                ->map(function (User $friend) use ($pendingSentIds, $acceptedIds) {
                    return array_merge($friend->toArray(), [
                        'friendship_status' => in_array($friend->id, $acceptedIds, true)
                            ? 'accepted'
                            : (in_array($friend->id, $pendingSentIds, true) ? 'pending_sent' : 'none'),
                    ]);
                });

            Log::info('[NearbyColleagues] query result', [
                'user_id' => $user->id,
                'count' => $nearbyUsers->count(),
                'user_ids' => $nearbyUsers->pluck('id')->values()->all(),
            ]);

            return response()->json([
                'type' => 'nearby',
                'data' => $nearbyUsers,
            ], 200);
        }

        $friendships = Friendship::query()
            ->where('teacher', 0)
            ->where(function ($query) use ($user) {
                $query->where('status', 'accepted')
                    ->where(function ($query) use ($user) {
                        $query->where('sender_id', $user->id)
                            ->orWhere('receiver_id', $user->id);
                    })
                    ->orWhere(function ($query) use ($user) {
                        $query->where('status', 'pending')
                            ->where('receiver_id', $user->id);
                    });
            })
            ->get();

        $friendshipMeta = $friendships->mapWithKeys(function (Friendship $friendship) use ($user) {
            if ($friendship->status === 'accepted') {
                $friendId = $friendship->sender_id === $user->id
                    ? $friendship->receiver_id
                    : $friendship->sender_id;

                return [$friendId => [
                    'friendship_status' => 'accepted',
                    'chat_id' => $this->ensureFriendshipChat($friendship),
                    'status' => $friendship->status,
                ]];
            }

            return [$friendship->sender_id => [
                'friendship_status' => 'pending_incoming',
                'chat_id' => $this->ensureFriendshipChat($friendship),
                'status' => $friendship->status,
            ]];
        });

        $friendIds = $friendshipMeta->keys()->all();

        $users = User::with('address')
            ->whereIn('id', $friendIds)
            ->get(['id', 'name', 'email', 'phone', 'school_grade'])
            ->map(function (User $friend) use ($friendshipMeta, $user) {
                $meta = $friendshipMeta[$friend->id] ?? [];
                $chatId = $meta['chat_id'] ?? null;

                if (empty($chatId)) {
                    $chatId = DB::table('chat_participants as me')
                        ->join('chat_participants as other', 'me.chat_id', '=', 'other.chat_id')
                        ->where('me.user_id', $user->id)
                        ->where('other.user_id', $friend->id)
                        ->value('me.chat_id');
                }

                return array_merge($friend->toArray(), [
                    'friendship_status' => $meta['friendship_status'] ?? 'accepted',
                    'chat_id' => $chatId,
                    'status' => $meta['status'] ?? 'accepted',
                ]);
            });

        return response()->json([
            'type' => 'colleagues',
            'data' => $users,
        ], 200);
    }

    public function resolveForUser(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'other_user_id' => ['required', 'integer', 'exists:users,id', 'not_in:' . $user->id],
        ]);

        $debugFriendship = [
            'request_data' => $request->all(),
            'validated_data' => $validated,
            'user_id' => $user->id,
            'existing_friendship_found' => false,
            'entering_teacher_flag_block' => false,
            'final_create_data' => null,
        ];

        $friendship = Friendship::query()
            ->where(function ($query) use ($user, $validated) {
                $query->where('sender_id', $user->id)
                    ->where('receiver_id', $validated['other_user_id']);
            })
            ->orWhere(function ($query) use ($user, $validated) {
                $query->where('sender_id', $validated['other_user_id'])
                    ->where('receiver_id', $user->id);
            })
            ->first();

        if (! $friendship) {
            $debugFriendship['entering_teacher_flag_block'] = true;
            $createData = [
                'sender_id' => $user->id,
                'receiver_id' => $validated['other_user_id'],
                'status' => 'accepted',
                'teacher' => 1,
            ];
            $debugFriendship['final_create_data'] = $createData;

            Log::info('TEACHER_FRIENDSHIP_DEBUG', $debugFriendship);

            $friendship = Friendship::create($createData);
        } else {
            $debugFriendship['existing_friendship_found'] = true;
            Log::info('TEACHER_FRIENDSHIP_DEBUG', $debugFriendship);
        }

        $chatId = $friendship->chat_id ?? null;

        if (empty($chatId)) {
            $chat = (new BaseChatController())->ensurePrivateChatForFriendshipPair($user->id, $validated['other_user_id']);
            $chatId = $chat->id;
            $friendship->forceFill(['chat_id' => $chatId])->save();
        }

        return response()->json([
            'found' => true,
            'friendship_id' => $friendship->id,
            'chat_id' => (int) $chatId,
            'friendship_status' => $friendship->status,
            'debug_friendship' => $debugFriendship,
        ], 200);
    }

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'receiver_id' => ['required', 'integer', 'exists:users,id', 'not_in:' . $user->id],
        ]);

        $exists = Friendship::query()
            ->where(function ($query) use ($user, $validated) {
                $query->where('sender_id', $user->id)
                    ->where('receiver_id', $validated['receiver_id']);
            })
            ->orWhere(function ($query) use ($user, $validated) {
                $query->where('sender_id', $validated['receiver_id'])
                    ->where('receiver_id', $user->id);
            })
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Friendship request already exists.',
            ], 409);
        }

        $chat = (new BaseChatController())->ensurePrivateChatForFriendshipPair($user->id, $validated['receiver_id']);

        $friendship = Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $validated['receiver_id'],
            'status' => 'pending',
            'chat_id' => $chat->id,
        ]);

        $receiver = User::find($validated['receiver_id']);
        if ($receiver) {
            $this->notificationService->sendNotification(
                $receiver,
                'طلب زمالة جديد',
                $user->name . ' أرسل لك طلب زمالة',
                [
                    'type' => 'friend_request',
                    'target_type' => 'friends',
                    'target_id' => $user->id,
                    'sender_id' => $user->id,
                    'friendship_id' => $friendship->id,
                ]
            );
        }

        return response()->json([
            'message' => 'Friend request sent successfully.',
            'data' => $friendship,
        ], 201);
    }

    public function acceptTeacher(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:users,id', 'not_in:' . $user->id],
        ]);

        $existing = Friendship::query()
            ->where(function ($query) use ($user, $validated) {
                $query->where('sender_id', $user->id)
                    ->where('receiver_id', $validated['teacher_id']);
            })
            ->orWhere(function ($query) use ($user, $validated) {
                $query->where('sender_id', $validated['teacher_id'])
                    ->where('receiver_id', $user->id);
            })
            ->first();

        $friendship = $existing ?: Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $validated['teacher_id'],
            'status' => 'accepted',
        ]);

        DB::transaction(function () use ($friendship, $user, $validated): void {
            $friendship->forceFill(['status' => 'accepted'])->save();
            $this->ensureFriendshipChat($friendship);
        });

        $friendship->refresh();

        return response()->json([
            'message' => 'Teacher approved successfully.',
            'data' => $friendship,
        ], $existing ? 200 : 201);
    }

    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'friend_id' => ['required', 'integer', 'exists:users,id', 'not_in:' . $user->id],
        ]);

        $friendship = Friendship::query()
            ->where(function ($query) use ($user, $validated) {
                $query->where('sender_id', $user->id)
                    ->where('receiver_id', $validated['friend_id']);
            })
            ->orWhere(function ($query) use ($user, $validated) {
                $query->where('sender_id', $validated['friend_id'])
                    ->where('receiver_id', $user->id);
            })
            ->first();

        if (! $friendship) {
            return response()->json([
                'message' => 'Friendship record not found.',
            ], 404);
        }

        $friendship->delete();

        return response()->json([
            'message' => 'Friendship canceled successfully.',
        ], 200);
    }

    public function accept(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'sender_id' => ['required', 'integer', 'exists:users,id', 'not_in:' . $user->id],
        ]);

        $friendship = Friendship::query()
            ->where('sender_id', $validated['sender_id'])
            ->where('receiver_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (! $friendship) {
            return response()->json([
                'message' => 'Pending friendship request not found.',
            ], 404);
        }

        DB::transaction(function () use ($friendship, $user): void {
            $friendship->update(['status' => 'accepted']);

            $sender = User::find($friendship->sender_id);
            if ($sender && $sender->id !== $user->id) {
                $this->notificationService->sendNotification(
                    $sender,
                    'تم قبول طلب الزمالة',
                    'تم قبول طلب الزمالة بواسطة ' . $user->name,
                    [
                        'type' => 'friend_request_accepted',
                        'target_type' => 'friends',
                        'target_id' => $user->id,
                        'sender_id' => $user->id,
                        'friendship_id' => $friendship->id,
                    ]
                );
            }

            $this->ensureFriendshipChat($friendship);
        });

        $friendship->refresh();

        return response()->json([
            'message' => 'Friend request accepted successfully.',
            'data' => $friendship,
        ], 200);
    }

    public function decline(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'sender_id' => ['required', 'integer', 'exists:users,id', 'not_in:' . $user->id],
        ]);

        $friendship = Friendship::query()
            ->where('sender_id', $validated['sender_id'])
            ->where('receiver_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (! $friendship) {
            return response()->json([
                'message' => 'Pending friendship request not found.',
            ], 404);
        }

        $friendship->delete();

        return response()->json([
            'message' => 'Friend request declined successfully.',
        ], 200);
    }

    private function ensureFriendshipChat(Friendship $friendship): int
    {
        if (! empty($friendship->chat_id)) {
            return (int) $friendship->chat_id;
        }

        $senderId = (int) $friendship->sender_id;
        $receiverId = (int) $friendship->receiver_id;

        $existingChat = Chat::query()
            ->where('type', 'private')
            ->whereHas('participants', function ($query) use ($senderId): void {
                $query->where('user_id', $senderId);
            })
            ->whereHas('participants', function ($query) use ($receiverId): void {
                $query->where('user_id', $receiverId);
            })
            ->first();

        if ($existingChat) {
            $friendship->forceFill(['chat_id' => $existingChat->id])->save();

            return (int) $existingChat->id;
        }

        $chat = (new BaseChatController())->ensurePrivateChatForFriendshipPair($senderId, $receiverId);
        $friendship->forceFill(['chat_id' => $chat->id])->save();

        return (int) $chat->id;
    }
}
