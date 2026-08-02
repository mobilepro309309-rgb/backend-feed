<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

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
        if (! in_array($type, ['colleagues', 'nearby'], true)) {
            return response()->json(['message' => 'Invalid type parameter.'], 422);
        }

        if ($type === 'nearby') {
            // Prefer explicit user_addresses row, but fall back to the Eloquent relation if missing.
            $currentUserAddress = DB::table('user_addresses')
                ->where('user_id', auth()->id())
                ->first();

            if (! $currentUserAddress && $user->address) {
                $currentUserAddress = (object) $user->address->toArray();
            }

            // If user lacks an address record, try using the free-text `location` field as a fallback.
            $useLocationFallback = false;
            if (! $currentUserAddress && ! empty($user->location)) {
                $useLocationFallback = true;
            }

            // Allow searches even when `school_grade` is not set: omit grade filter in that case.
            $schoolGrade = $user->school_grade;

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

            $nearbyQuery = User::with('address')
                ->where('id', '!=', $user->id)
                ->when(! empty($schoolGrade), function ($q) use ($schoolGrade) {
                    $q->where('school_grade', $schoolGrade);
                });

            if ($currentUserAddress) {
                $nearbyQuery->whereHas('address', function ($query) use ($currentUserAddress) {
                    $query->where('governorate', $currentUserAddress->governorate)
                        ->where('city_or_center', $currentUserAddress->city_or_center);
                });
            } elseif ($useLocationFallback) {
                // Fallback to matching the free-text `location` when no address record exists
                $nearbyQuery->where('location', $user->location);
            } else {
                // If we couldn't determine user's location, return empty collection
                return response()->json([
                    'type' => 'nearby',
                    'data' => collect(),
                ], 200);
            }

            $nearbyUsers = $nearbyQuery
                ->when(count($blockedIds) > 0, function ($query) use ($blockedIds) {
                    $query->whereNotIn('id', $blockedIds);
                })
                ->when(count($acceptedIds) > 0, function ($query) use ($acceptedIds) {
                    $query->whereNotIn('id', $acceptedIds);
                })
                ->get(['id', 'name', 'email', 'phone', 'school_grade'])
                ->map(function (User $friend) use ($pendingSentIds) {
                    return array_merge($friend->toArray(), [
                        'friendship_status' => in_array($friend->id, $pendingSentIds, true)
                            ? 'pending_sent'
                            : 'none',
                    ]);
                });

            return response()->json([
                'type' => 'nearby',
                'data' => $nearbyUsers,
            ], 200);
        }

        $friendships = Friendship::query()
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
                    'chat_id' => $friendship->chat_id ?? null,
                    'status' => $friendship->status,
                ]];
            }

            return [$friendship->sender_id => [
                'friendship_status' => 'pending_incoming',
                'chat_id' => null,
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
            $friendship = Friendship::create([
                'sender_id' => $user->id,
                'receiver_id' => $validated['other_user_id'],
                'status' => 'accepted',
            ]);
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

        $friendship = Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $validated['receiver_id'],
            'status' => 'pending',
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

            if (! empty($friendship->chat_id)) {
                return;
            }

            $chat = Chat::create(['type' => 'private']);

            $participantPairs = [
                ['chat_id' => $chat->id, 'user_id' => $user->id],
                ['chat_id' => $chat->id, 'user_id' => $validated['teacher_id']],
            ];

            foreach ($participantPairs as $participantData) {
                ChatParticipant::firstOrCreate(
                    $participantData,
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            $friendship->forceFill(['chat_id' => $chat->id])->save();
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

            if (! empty($friendship->chat_id)) {
                return;
            }

            $existingChat = Chat::query()
                ->where('type', 'private')
                ->whereHas('participants', function ($query) use ($friendship): void {
                    $query->where('user_id', $friendship->sender_id);
                })
                ->whereHas('participants', function ($query) use ($friendship): void {
                    $query->where('user_id', $friendship->receiver_id);
                })
                ->first();

            $chat = $existingChat;

            if (! $chat) {
                $chat = Chat::create(['type' => 'private']);

                ChatParticipant::insert([
                    ['chat_id' => $chat->id, 'user_id' => $friendship->sender_id, 'created_at' => now(), 'updated_at' => now()],
                    ['chat_id' => $chat->id, 'user_id' => $friendship->receiver_id, 'created_at' => now(), 'updated_at' => now()],
                ]);
            }

            $friendship->update(['chat_id' => $chat->id]);
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
}
