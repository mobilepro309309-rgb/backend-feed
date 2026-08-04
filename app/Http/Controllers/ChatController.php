<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Log, Validator};
use Illuminate\Support\Str;

use App\Events\{MessageSent, MessageStatusUpdated, MessagesRead, UserTyping};
use App\Models\{Chat, ChatParticipant, Friendship, Message, User};
use App\Services\NotificationService;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $userId = (int) auth()->id();

        $chatIds = ChatParticipant::where('user_id', $userId)
            ->pluck('chat_id');

        $chats = Chat::whereIn('id', $chatIds)
            ->select(['id', 'type', 'teacher_id', 'created_at', 'updated_at'])
            ->with(['participants' => function ($query) use ($userId): void {
                $query->select(['id', 'chat_id', 'user_id'])
                    ->where('user_id', '!=', $userId)
                    ->with('user:id,name');
            }])
            ->with(['messages' => function ($query): void {
                $query->select(['id', 'chat_id', 'sender_id', 'text', 'created_at', 'updated_at'])
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(1);
            }])
            ->latest('updated_at')
            ->paginate(20);

        $chats->getCollection()->transform(function (Chat $chat): Chat {
            $lastMessage = $chat->messages->first();

            $chat->last_message = $lastMessage ? [
                'id' => $lastMessage->id,
                'sender_id' => $lastMessage->sender_id,
                'text' => $lastMessage->text,
                'created_at' => $lastMessage->created_at,
                'updated_at' => $lastMessage->updated_at,
            ] : null;

            $chat->unsetRelation('messages');

            return $chat;
        });

        return response()->json([
            'chats' => $chats,
        ], 200);
    }

    public function resolveOrCreateChat(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $senderId = (int) auth()->id();
        $receiverId = (int) $validated['receiver_id'];

        $existingChat = Chat::where('type', 'private')
            ->where('teacher_id', $receiverId)
            ->first();

        $ensureFriendshipForChat = function (int $chatId) use ($senderId, $receiverId): void {
            $friendshipExists = Friendship::query()
                ->where(function ($query) use ($senderId, $receiverId): void {
                    $query->where('sender_id', $senderId)
                        ->where('receiver_id', $receiverId);
                })
                ->orWhere(function ($query) use ($senderId, $receiverId): void {
                    $query->where('sender_id', $receiverId)
                        ->where('receiver_id', $senderId);
                })
                ->exists();

            if (! $friendshipExists) {
                Friendship::create([
                    'chat_id' => $chatId,
                    'sender_id' => $senderId,
                    'receiver_id' => $receiverId,
                    'status' => 'accepted',
                ]);
            }
        };

        if ($existingChat) {
            $ensureFriendshipForChat((int) $existingChat->id);

            return response()->json([
                'chat_id' => (int) $existingChat->id,
                'created' => false,
            ], 200);
        }

        return DB::transaction(function () use ($receiverId, $senderId, $ensureFriendshipForChat) {
            $chat = Chat::create([
                'type' => 'private',
                'teacher_id' => $receiverId,
            ]);

            foreach ([
                ['chat_id' => $chat->id, 'user_id' => $senderId],
                ['chat_id' => $chat->id, 'user_id' => $receiverId],
            ] as $participantData) {
                ChatParticipant::firstOrCreate(
                    $participantData,
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            $ensureFriendshipForChat((int) $chat->id);

            return response()->json([
                'chat_id' => (int) $chat->id,
                'created' => true,
            ], 200);
        });
    }

    public function ensurePrivateChatForFriendshipPair(int $senderId, int $receiverId): Chat
    {
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
            return $existingChat;
        }

        return DB::transaction(function () use ($senderId, $receiverId): Chat {
            $chat = Chat::create(['type' => 'private']);

            ChatParticipant::insert([
                ['chat_id' => $chat->id, 'user_id' => $senderId, 'created_at' => now(), 'updated_at' => now()],
                ['chat_id' => $chat->id, 'user_id' => $receiverId, 'created_at' => now(), 'updated_at' => now()],
            ]);

            return $chat;
        });
    }

    public function getOrCreatePrivateChat(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id', 'not_in:' . $user->id],
        ]);

        $recipientId = (int) $validated['recipient_id'];

        $friendship = Friendship::query()
            ->where(function ($query) use ($user, $recipientId) {
                $query->where('sender_id', $user->id)
                    ->where('receiver_id', $recipientId);
            })
            ->orWhere(function ($query) use ($user, $recipientId) {
                $query->where('sender_id', $recipientId)
                    ->where('receiver_id', $user->id);
            })
            ->first();

        if ($friendship && ! empty($friendship->chat_id)) {
            $chat = Chat::find($friendship->chat_id);
            if ($chat) {
                $recipient = User::find($recipientId, ['id', 'name', 'email', 'school_grade']);

                return response()->json([
                    'status' => 'success',
                    'chat_id' => (int) $friendship->chat_id,
                    'recipient' => $recipient,
                ], 200);
            }
        }

        return DB::transaction(function () use ($user, $recipientId, $friendship) {
            $chat = Chat::create(['type' => 'private']);

            if ($friendship) {
                $friendship->forceFill(['chat_id' => $chat->id])->save();
            } else {
                Friendship::create([
                    'sender_id' => $user->id,
                    'receiver_id' => $recipientId,
                    'status' => 'accepted',
                    'chat_id' => $chat->id,
                ]);
            }

            ChatParticipant::insert([
                ['chat_id' => $chat->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
                ['chat_id' => $chat->id, 'user_id' => $recipientId, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $recipient = User::find($recipientId, ['id', 'name', 'email', 'school_grade']);

            return response()->json([
                'status' => 'created',
                'chat_id' => (int) $chat->id,
                'recipient' => $recipient,
            ], 200);
        });
    }

    public function ensureParticipant(Request $request, $chatId)
    {
        $validated = $request->validate([
            'chat_id' => ['nullable', 'integer', 'exists:chats,id'],
        ]);

        $resolvedChatId = isset($validated['chat_id']) ? (int) $validated['chat_id'] : (int) $chatId;
        $userId = (int) auth()->id();

        $chat = Chat::find($resolvedChatId);
        if (! $chat) {
            return response()->json([
                'message' => 'Chat not found.',
            ], 404);
        }

        $exists = ChatParticipant::where('chat_id', $resolvedChatId)
            ->where('user_id', $userId)
            ->exists();

        if (! $exists) {
            ChatParticipant::firstOrCreate(
                ['chat_id' => $resolvedChatId, 'user_id' => $userId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        return response()->json([
            'chat_id' => (int) $resolvedChatId,
            'participant_added' => ! $exists,
        ], 200);
    }

    public function resolveChatByTeacher(Request $request)
    {
        $validated = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $teacherId = (int) $validated['teacher_id'];

        $chat = Chat::where('type', 'private')
            ->where('teacher_id', $teacherId)
            ->first();

        return response()->json([
            'chat_id' => $chat ? (int) $chat->id : null,
            'found' => (bool) $chat,
        ], 200);
    }

    public function getPendingQuestions(Request $request)
    {
        $teacherId = (int) auth()->id();

        if (! $teacherId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $friendships = Friendship::query()
            ->where(function ($query) use ($teacherId): void {
                $query->where('sender_id', $teacherId)
                    ->orWhere('receiver_id', $teacherId);
            })
            ->where('teacher', 1)
            ->get();

        $result = [];

        foreach ($friendships as $friendship) {
            $studentId = (int) ($friendship->sender_id === $teacherId ? $friendship->receiver_id : $friendship->sender_id);
            $chatId = $this->ensureFriendshipChat($friendship);
            $student = User::find($studentId, ['id', 'name', 'school_grade', 'role']);

            if (! $student) {
                continue;
            }

            $latestMessage = Message::query()
                ->where('chat_id', $chatId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $latestMessageTimestamp = $latestMessage?->created_at ?? $friendship->updated_at ?? $friendship->created_at;
            $hasMessaged = (bool) $latestMessage;

            $result[] = [
                'chat_id' => (int) $chatId,
                'teacher_id' => $teacherId,
                'student_id' => (int) $student->id,
                'student_name' => $student->name,
                'school_grade' => $student->school_grade,
                'student_role' => $student->role,
                'student' => $student->only(['id', 'name', 'school_grade', 'role']),
                'friendship' => [
                    'id' => $friendship->id,
                    'sender_id' => $friendship->sender_id,
                    'receiver_id' => $friendship->receiver_id,
                    'status' => $friendship->status,
                    'teacher' => (int) ($friendship->teacher ?? 0),
                    'chat_id' => (int) $chatId,
                ],
                'last_message' => $latestMessage?->text ?? 'سؤال/بطاقة تفاعلية',
                'message_type' => $latestMessage?->message_type ?? 'text',
                'has_messaged' => $hasMessaged,
                'last_message_at' => $latestMessage?->created_at?->toISOString() ?? null,
                'last_message_time' => $latestMessage?->created_at?->toIso8601String() ?? null,
                'sent_by_student' => $latestMessage ? (int) $latestMessage->sender_id !== $teacherId : true,
                'needs_teacher_reply' => $latestMessage ? (int) $latestMessage->sender_id !== $teacherId : true,
                'created_at' => $latestMessage?->created_at,
                'updated_at' => $latestMessage?->updated_at,
                '_latest_activity_at' => $latestMessageTimestamp,
            ];
        }

        $result = collect($result)
            ->sortByDesc(function ($item) {
                return $item['_latest_activity_at'] ?? null;
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $result,
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

        $chat = $this->ensurePrivateChatForFriendshipPair($senderId, $receiverId);
        $friendship->forceFill(['chat_id' => $chat->id])->save();

        return (int) $chat->id;
    }

    protected function ensureFriendshipForChatInitiation(int $senderId, int $receiverId, int $chatId, Request $request): Friendship
    {
        $recipient = User::find($receiverId, ['id', 'role']);
        $requestSignalsTeacher = false;

        foreach (['teacher', 'is_teacher', 'target_type', 'sendTargetType', 'teacher_id'] as $field) {
            $value = $request->input($field);

            if (is_bool($value) && $value) {
                $requestSignalsTeacher = true;
                break;
            }

            if (is_numeric($value) && (int) $value === 1) {
                $requestSignalsTeacher = true;
                break;
            }

            if (is_string($value) && mb_strtolower(trim($value)) === 'teacher') {
                $requestSignalsTeacher = true;
                break;
            }
        }

        $isTeacherRelated = $requestSignalsTeacher || ($recipient && mb_strtolower((string) ($recipient->role ?? '')) === 'teacher');

        $friendship = Friendship::query()
            ->where(function ($query) use ($senderId, $receiverId): void {
                $query->where('sender_id', $senderId)
                    ->where('receiver_id', $receiverId);
            })
            ->orWhere(function ($query) use ($senderId, $receiverId): void {
                $query->where('sender_id', $receiverId)
                    ->where('receiver_id', $senderId);
            })
            ->first();

        if ($friendship) {
            $friendship->forceFill([
                'chat_id' => $chatId,
                'teacher' => $isTeacherRelated ? 1 : ($friendship->teacher ?? 0),
            ])->save();

            return $friendship;
        }

        return Friendship::create([
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'status' => 'accepted',
            'chat_id' => $chatId,
            'teacher' => $isTeacherRelated ? 1 : 0,
        ]);
    }

    public function sendMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receiver_id' => ['nullable', 'integer', 'exists:users,id'],
            'text' => ['nullable', 'string', 'max:10000', 'required_without:file_url'],
            'chat_id' => ['nullable', 'integer', 'exists:chats,id'],
            'reply_to_id' => ['nullable', 'integer', 'exists:messages,id'],
            'message_type' => ['nullable', 'string'],
            'shared_content_id' => ['nullable', 'string'],
            'shared_content_type' => ['nullable', 'string'],
            'file_url' => ['nullable', 'string', 'required_without:text'],
            'file_type' => ['nullable', 'string'],
            'file_name' => ['nullable', 'string'],
            'file_size' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            Log::error('SEND MESSAGE VALIDATION FAILED', ['errors' => $errors->toArray(), 'request' => $request->all()]);

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $validated = $validator->validated();
        $senderId = (int) auth()->id();
        $receiverId = isset($validated['receiver_id']) ? (int) $validated['receiver_id'] : null;
        $chatId = $validated['chat_id'] ?? null;
        $resolvedMessageType = $this->resolveMessageType($request, $validated);

        if (! empty($validated['file_url'])) {
            $fileType = trim((string) ($validated['file_type'] ?? ''));
            if ($resolvedMessageType === 'text' || $resolvedMessageType === '') {
                $resolvedMessageType = match ($fileType) {
                    'image' => 'image',
                    'video' => 'video',
                    'audio' => 'audio',
                    'document' => 'document',
                    default => 'media',
                };
            }
        }

        $resolvedMessageText = $this->resolveMessageText($request, $validated, $resolvedMessageType);

        try {
            if ($chatId) {
                $chat = Chat::find($chatId);

                if (! $chat) {
                    return response()->json([
                        'message' => 'Chat not found.',
                    ], 404);
                }

                if (! $receiverId) {
                    $receiverId = (int) ChatParticipant::where('chat_id', $chatId)
                        ->where('user_id', '!=', $senderId)
                        ->value('user_id');
                }
            } else {
                if (! $receiverId) {
                    return response()->json([
                        'message' => 'receiver_id is required for a new chat.',
                    ], 422);
                }

                $senderChatIds = ChatParticipant::where('user_id', $senderId)
                    ->pluck('chat_id');

                $receiverChatIds = ChatParticipant::where('user_id', $receiverId)
                    ->pluck('chat_id');

                $existingChatId = Chat::whereIn('id', $senderChatIds->intersect($receiverChatIds))
                    ->where('type', 'private')
                    ->value('id');

                if ($existingChatId) {
                    $chatId = (int) $existingChatId;
                } else {
                    DB::beginTransaction();

                    try {
                        $chat = Chat::create([
                            'type' => 'private',
                        ]);

                        foreach ([
                            ['chat_id' => $chat->id, 'user_id' => $senderId],
                            ['chat_id' => $chat->id, 'user_id' => $receiverId],
                        ] as $participantData) {
                            ChatParticipant::firstOrCreate(
                                $participantData,
                                ['created_at' => now(), 'updated_at' => now()]
                            );
                        }

                        $this->ensureFriendshipForChatInitiation($senderId, $receiverId, (int) $chat->id, $request);

                        $message = Message::create([
                            'chat_id' => $chat->id,
                            'sender_id' => $senderId,
                            'text' => $resolvedMessageText,
                            'message_type' => $resolvedMessageType,
                            'status' => 'sent',
                            'reply_to_id' => $validated['reply_to_id'] ?? null,
                            'file_url' => $validated['file_url'] ?? null,
                            'file_type' => $validated['file_type'] ?? null,
                            'file_name' => $validated['file_name'] ?? null,
                            'file_size' => $validated['file_size'] ?? null,
                        ]);

                        DB::commit();
                        $chatId = (int) $chat->id;
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        error_log('SEND MESSAGE ERROR: ' . $e->getMessage());
                        Log::error('SEND MESSAGE ERROR DETAILS', [
                            'error' => $e->getMessage(),
                            'request' => $request->all(),
                        ]);
                        Log::error('Chat message creation failed', [
                            'sender_id' => $senderId,
                            'receiver_id' => $receiverId,
                            'error' => $e->getMessage(),
                            'payload' => $validated,
                        ]);

                        return response()->json([
                            'message' => 'Unable to create chat message.',
                        ], 500);
                    }
                }
            }

            if (! isset($message)) {
                DB::beginTransaction();

                try {
                    $message = Message::create([
                        'chat_id' => $chatId,
                        'sender_id' => $senderId,
                        'text' => $resolvedMessageText,
                        'message_type' => $resolvedMessageType,
                        'status' => 'sent',
                        'reply_to_id' => $validated['reply_to_id'] ?? null,
                        'file_url' => $validated['file_url'] ?? null,
                        'file_type' => $validated['file_type'] ?? null,
                        'file_name' => $validated['file_name'] ?? null,
                        'file_size' => $validated['file_size'] ?? null,
                    ]);

                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    error_log('SEND MESSAGE ERROR: ' . $e->getMessage());
                    Log::error('SEND MESSAGE ERROR DETAILS', [
                        'error' => $e->getMessage(),
                        'request' => $request->all(),
                    ]);
                    Log::error('Chat message save failed', [
                        'chat_id' => $chatId,
                        'sender_id' => $senderId,
                        'receiver_id' => $receiverId,
                        'error' => $e->getMessage(),
                        'payload' => $validated,
                    ]);

                    return response()->json([
                        'message' => 'Unable to save chat message.',
                    ], 500);
                }
            }

            try {
                $this->dispatchChatPushNotification(
                    $receiverId,
                    $senderId,
                    $chatId,
                    $resolvedMessageType,
                    $resolvedMessageText
                );
            } catch (\Throwable $notificationException) {
                Log::error('Chat push dispatch failed', [
                    'sender_id' => $senderId,
                    'receiver_id' => $receiverId,
                    'chat_id' => $chatId,
                    'error' => $notificationException->getMessage(),
                ]);
            }

            broadcast(new MessageSent($message))->toOthers();

            return response()->json([
                'message' => $message,
                'chat_id' => $chatId,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Chat send message failed', [
                'sender_id' => $senderId,
                'receiver_id' => $receiverId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unexpected error while sending message.',
            ], 500);
        }
    }

    public function createSharedMessage(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => ['required', 'integer', 'exists:users,id'],
            'chat_id' => ['nullable', 'integer', 'exists:chats,id'],
            'text' => ['nullable', 'string', 'max:10000', 'required_without:file_url'],
            'message_type' => ['nullable', 'string'],
            'feed_type' => ['nullable', 'string'],
            'shared_content_id' => ['nullable', 'string'],
            'shared_content_type' => ['nullable', 'string'],
            'file_url' => ['nullable', 'string', 'required_without:text'],
            'file_type' => ['nullable', 'string'],
            'file_name' => ['nullable', 'string'],
            'file_size' => ['nullable', 'integer'],
        ]);

        $senderId = (int) auth()->id();
        $receiverId = (int) $validated['receiver_id'];
        $chatId = $validated['chat_id'] ?? null;
        $resolvedMessageType = $this->resolveMessageType($request, $validated);
        $resolvedMessageText = $this->resolveMessageText($request, $validated, $resolvedMessageType);

        Log::info('createSharedMessage payload', [
            'receiver_id' => $receiverId,
            'chat_id' => $chatId,
            'raw_message_type' => $request->input('message_type'),
            'raw_feed_type' => $request->input('feed_type'),
            'raw_shared_content_type' => $request->input('shared_content_type'),
            'resolved_message_type' => $resolvedMessageType,
            'request_payload' => $request->all(),
        ]);

        if (! $chatId) {
            $senderChatIds = ChatParticipant::where('user_id', $senderId)->pluck('chat_id');
            $receiverChatIds = ChatParticipant::where('user_id', $receiverId)->pluck('chat_id');
            $existingChatId = Chat::whereIn('id', $senderChatIds->intersect($receiverChatIds))
                ->where('type', 'private')
                ->value('id');

            if ($existingChatId) {
                $chatId = (int) $existingChatId;
            } else {
                $chat = Chat::create(['type' => 'private']);
                foreach ([
                    ['chat_id' => $chat->id, 'user_id' => $senderId],
                    ['chat_id' => $chat->id, 'user_id' => $receiverId],
                ] as $participantData) {
                    ChatParticipant::firstOrCreate(
                        $participantData,
                        ['created_at' => now(), 'updated_at' => now()]
                    );
                }

                $this->ensureFriendshipForChatInitiation($senderId, $receiverId, (int) $chat->id, $request);
                $chatId = (int) $chat->id;
            }
        }

        if (! empty($validated['file_url']) && ($resolvedMessageType === 'text' || $resolvedMessageType === '')) {
            $fileType = trim((string) ($validated['file_type'] ?? ''));
            $resolvedMessageType = match ($fileType) {
                'image' => 'image',
                'video' => 'video',
                'audio' => 'audio',
                'document' => 'document',
                default => 'media',
            };
        }

        $message = Message::create([
            'chat_id' => $chatId,
            'sender_id' => $senderId,
            'text' => $resolvedMessageText,
            'message_type' => $resolvedMessageType,
            'status' => 'sent',
            'file_url' => $validated['file_url'] ?? null,
            'file_type' => $validated['file_type'] ?? null,
            'file_name' => $validated['file_name'] ?? null,
            'file_size' => $validated['file_size'] ?? null,
        ]);

        // Log detailed info for shared message creation (helpful for debugging quiz share payloads)
        Log::info('createSharedMessage.created', [
            'message_id' => $message->id,
            'chat_id' => $chatId,
            'sender_id' => $senderId,
            'resolved_message_type' => $resolvedMessageType,
            'resolved_message_text' => $resolvedMessageText,
            'request' => $request->all(),
        ]);

        broadcast(new MessageSent($message))->toOthers();

        return response()->json([
            'message' => $message,
            'chat_id' => $chatId,
        ], 200);
    }

    protected function resolveMessageText(Request $request, array $validated, string $resolvedMessageType): string
    {
        if ($resolvedMessageType === 'text') {
            return (string) ($validated['text'] ?? $request->input('text', ''));
        }

        $sharedContentId = $request->input('shared_content_id')
            ?? $validated['shared_content_id'] ?? null;

        if (is_scalar($sharedContentId) && (string) $sharedContentId !== '') {
            return (string) $sharedContentId;
        }

        return (string) ($validated['text'] ?? $request->input('text', ''));
    }

    protected function resolveMessageType(Request $request, array $validated): string
    {
        $candidates = collect([
            $validated['message_type'] ?? null,
            $request->input('message_type'),
            $request->input('shared_content_type'),
            $request->input('feed_type'),
            $request->input('type'),
            $request->input('shared_content_type'),
            $request->input('shared_content.type'),
            $request->input('shared_content.data.type'),
            $request->input('shared_content.feed_type'),
            $request->input('shared_content.type'),
        ])->filter(static fn ($value) => is_string($value) || is_numeric($value))
            ->map(static fn ($value) => trim((string) $value))
            ->filter(static fn ($value) => $value !== '')
            ->values();

        foreach ($candidates as $candidate) {
            $normalized = mb_strtolower($candidate);

            if (str_starts_with($normalized, 'image/')) {
                return 'image';
            }

            if (str_starts_with($normalized, 'video/')) {
                return 'video';
            }

            if (str_starts_with($normalized, 'audio/')) {
                return 'audio';
            }

            if (in_array($normalized, ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'], true)) {
                return 'document';
            }

            $mapped = match ($normalized) {
                'text' => 'text',
                'post' => 'post',
                'image' => 'image',
                'video' => 'video',
                'audio' => 'audio',
                'document' => 'document',
                'media' => 'media',
                'multiplechoicequiz' => 'MultipleChoiceQuiz',
                'multiple-choice-quiz' => 'MultipleChoiceQuiz',
                'multiple-choice-question' => 'MultipleChoiceQuiz',
                'multiple_choice_question' => 'MultipleChoiceQuiz',
                'multiple_choice' => 'MultipleChoiceQuiz',
                'mcq' => 'MultipleChoiceQuiz',
                'truefalsequiz' => 'TrueFalseQuiz',
                'true-false-quiz' => 'TrueFalseQuiz',
                'true-false-question' => 'TrueFalseQuiz',
                'true_false_question' => 'TrueFalseQuiz',
                'true_false' => 'TrueFalseQuiz',
                'truefalse' => 'TrueFalseQuiz',
                'findthebugquiz' => 'FindTheBugQuiz',
                'find-the-bug-quiz' => 'FindTheBugQuiz',
                'find-the-bug-challenge' => 'FindTheBugQuiz',
                'find_the_bug_challenge' => 'FindTheBugQuiz',
                'dailychallengequiz' => 'DailyChallengeQuiz',
                'daily-challenge-quiz' => 'DailyChallengeQuiz',
                'daily-challenge' => 'DailyChallengeQuiz',
                'daily_challenge' => 'DailyChallengeQuiz',
                'comparisoncardquiz' => 'ComparisonCardQuiz',
                'comparison-card-quiz' => 'ComparisonCardQuiz',
                'comparison-challenge' => 'ComparisonCardQuiz',
                'comparison_challenge' => 'ComparisonCardQuiz',
                'comparison-card' => 'ComparisonCardQuiz',
                'comparison_card' => 'ComparisonCardQuiz',
                'cheatsheetflipcardquiz' => 'CheatSheetFlipCardQuiz',
                'cheat-sheet-flip-card-quiz' => 'CheatSheetFlipCardQuiz',
                'cheat-sheet-flip' => 'CheatSheetFlipCardQuiz',
                'cloud-capsule-challenge' => 'CheatSheetFlipCardQuiz',
                'cloud_capsule_challenge' => 'CheatSheetFlipCardQuiz',
                'cloud-capsule' => 'CheatSheetFlipCardQuiz',
                'cloud_capsule' => 'CheatSheetFlipCardQuiz',
                'liveduelcardquiz' => 'LiveDuelCardQuiz',
                'live-duel-card-quiz' => 'LiveDuelCardQuiz',
                'live-duel-challenge' => 'LiveDuelCardQuiz',
                'live_duel_challenge' => 'LiveDuelCardQuiz',
                'live-duel' => 'LiveDuelCardQuiz',
                'live_duel' => 'LiveDuelCardQuiz',
                default => null,
            };

            if ($mapped !== null) {
                return $mapped;
            }
        }

        return 'text';
    }

    protected function resolveChatNotificationBody(string $resolvedMessageType, ?string $messageText): string
    {
        $normalizedType = mb_strtolower(trim((string) $resolvedMessageType));
        $text = trim((string) $messageText);

        if ($normalizedType === 'text') {
            return Str::limit($text ?: 'لديك رسالة جديدة', 100, '...');
        }

        if (in_array($normalizedType, ['image', 'photo', 'picture'], true)) {
            return '📷 أرسل لك صورة';
        }

        if ($normalizedType === 'audio') {
            return '🎤 أرسل لك رسالة صوتية';
        }

        if (in_array($normalizedType, ['document', 'file', 'media', 'attachment'], true)) {
            return '📁 أرسل لك ملفاً';
        }

        if (in_array($normalizedType, ['post', 'multiplechoicequiz', 'truefalsequiz', 'findthebugquiz', 'dailychallengequiz', 'comparisoncardquiz', 'liveduelcardquiz', 'question', 'quiz', 'challenge'], true)) {
            return '❓ أرسل لك سؤالاً/تحدياً';
        }

        return Str::limit($text ?: 'لديك رسالة جديدة', 100, '...');
    }

    protected function dispatchChatPushNotification(int $recipientId, int $senderId, int $chatId, string $resolvedMessageType, string $resolvedMessageText): void
    {
        if ($recipientId <= 0 || $recipientId === $senderId) {
            return;
        }

        $recipient = User::find($recipientId);
        if (! $recipient) {
            return;
        }

        $sender = auth()->user();
        $title = trim((string) optional($sender)->name) ?: 'رسالة جديدة';
        $body = $this->resolveChatNotificationBody($resolvedMessageType, $resolvedMessageText);

        $payload = [
            'title' => $title,
            'body' => $body,
            'data' => [
                'type' => 'private_chat',
                'target_type' => 'chat',
                'action_type' => 'chat',
                'chat_id' => (string) $chatId,
                'sender_id' => (string) $senderId,
                'sender_name' => (string) optional($sender)->name,
                'channelId' => 'chat_messages',
            ],
        ];

        try {
            app(NotificationService::class)->sendNotificationToUser($recipientId, $payload);
        } catch (\Throwable $e) {
            Log::error('Chat Push Notification Failed', [
                'recipient_id' => $recipientId,
                'sender_id' => $senderId,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getMessages(Request $request, $chatId)
    {
        \Log::emergency('API HIT: Request received for ChatID: ' . $chatId);

        $limit = max(1, min(100, (int) $request->input('limit', 5)));
        $beforeId = $request->input('before_id');
        $page = max(1, (int) $request->input('page', 1));
        $offset = $request->input('offset');

        $query = \App\Models\Message::where('chat_id', $chatId)
            ->with(['replyTo' => function ($query) {
                $query->select([
                    'id',
                    'chat_id',
                    'sender_id',
                    'text',
                    'created_at',
                    'file_url',
                    'file_type',
                    'file_name',
                    'file_size',
                ]);
            }]);

        if ($beforeId !== null) {
            $query->where('id', '<', (int) $beforeId);
        } elseif ($page > 1) {
            $query->skip(($page - 1) * $limit);
        } elseif (is_numeric($offset)) {
            $query->skip((int) $offset);
        }

        $messages = $query
            ->orderBy('created_at', 'desc')
            ->orderByDesc('id')
            ->take($limit)
            ->get()
            ->reverse()
            ->values();

            // Attempt to enrich messages that reference shared quiz content by resolving payloads
            $enriched = $messages->map(function ($message) {
                $m = $message->toArray();

                $msgType = strtolower((string) ($message->message_type ?? ''));
                $sharedId = $message->text ?? null; // resolveMessageText stored shared_content_id in text for shared messages

                if ($sharedId && preg_match('/^\d+$/', (string) $sharedId)) {
                    $id = (string) $sharedId;

                    try {
                        if (str_contains($msgType, 'multiple') || str_contains($msgType, 'mcq')) {
                            $controller = app(\App\Http\Controllers\Api\Questions\MultipleChoiceQuestionController::class);
                            $res = $controller->show($id);
                        } elseif (str_contains($msgType, 'true')) {
                            $controller = app(\App\Http\Controllers\Api\Questions\TrueFalseQuestionController::class);
                            $res = $controller->show($id);
                        } else {
                            $res = null;
                        }

                        if ($res && method_exists($res, 'getData')) {
                            $payload = $res->getData(true);
                            if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
                                $m['shared_payload'] = $payload['data'];
                            } else {
                                $m['shared_payload'] = is_array($payload) ? $payload : $payload;
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore resolution errors
                    }
                }

                return $m;
            });

            \Log::emergency('API RESULT: Prepared response for ChatID: ' . $chatId);

            return response()->json(['messages' => $enriched], 200);
    }

    public function markAsDelivered(Request $request, $chatId)
    {
        $userId = (int) auth()->id();

        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->exists();

        if (! $isParticipant) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $updatedMessages = Message::where('chat_id', $chatId)
            ->where('sender_id', '!=', $userId)
            ->where('status', 'sent')
            ->get();

        foreach ($updatedMessages as $message) {
            $message->status = 'delivered';
            $message->save();
            broadcast(new MessageStatusUpdated($message))->toOthers();
        }

        return response()->json([
            'message' => 'Messages marked as delivered.',
            'updated_count' => $updatedMessages->count(),
        ], 200);
    }

    public function markAsRead(Request $request, $chatId = null)
    {
        $chatId = $chatId ?? $request->input('chat_id') ?? $request->route('chat_id');

        if (! $chatId) {
            return response()->json([
                'message' => 'chat_id is required.',
            ], 422);
        }

        $userId = (int) auth()->id();

        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->exists();

        if (! $isParticipant) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $messagesToRead = Message::where('chat_id', $chatId)
            ->where('sender_id', '!=', $userId)
            ->where('status', '!=', 'read')
            ->get();

        foreach ($messagesToRead as $message) {
            $message->status = 'read';
            $message->save();
            broadcast(new MessageStatusUpdated($message))->toOthers();
        }

        event(new MessagesRead($chatId, $userId, $messagesToRead->count()));

        return response()->json([
            'message' => 'Messages marked as read.',
            'updated_count' => $messagesToRead->count(),
        ], 200);
    }

    public function broadcastTyping(Request $request, $chatId = null)
    {
        $chatId = $chatId ?? $request->input('chat_id') ?? $request->route('chat_id');

        if (! $chatId) {
            return response()->json([
                'message' => 'chat_id is required.',
            ], 422);
        }

        $userId = (int) auth()->id();

        $validated = $request->validate([
            'is_typing' => ['required', 'boolean'],
        ]);

        $isTyping = (bool) $validated['is_typing'];

        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->exists();

        if (! $isParticipant) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $typingKey = 'chat_typing:' . (int) $chatId;
        $typingState = Cache::get($typingKey, []);

        if ($isTyping) {
            $typingState[$userId] = [
                'user_id' => $userId,
                'is_typing' => true,
                'timestamp' => now()->toISOString(),
            ];
        } else {
            unset($typingState[$userId]);
        }

        Cache::put($typingKey, $typingState, now()->addSeconds(3));

        broadcast(new UserTyping((int) $chatId, $userId, $isTyping))->toOthers();

        return response()->json([
            'message' => $isTyping ? 'Typing started.' : 'Typing stopped.',
        ], 200);
    }

    public function getTypingStatus(Request $request, $chatId)
    {
        $userId = (int) auth()->id();

        $isParticipant = ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->exists();

        if (! $isParticipant) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $typingKey = 'chat_typing:' . (int) $chatId;
        $typingState = Cache::get($typingKey, []);
        $activeTyping = collect($typingState)
            ->filter(fn ($entry) => ! empty($entry['is_typing']))
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'chat_id' => (int) $chatId,
                'is_typing' => count($activeTyping) > 0,
                'typing_users' => collect($activeTyping)->pluck('user_id')->all(),
            ],
        ], 200);
    }

    public function getInbox(Request $request)
    {
        $userId = (int) auth()->id();

        $latestMessageSubquery = Message::select('text')
            ->whereColumn('messages.chat_id', 'chats.id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);

        $latestMessageTimeSubquery = Message::select('created_at')
            ->whereColumn('messages.chat_id', 'chats.id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);

        $unreadCountSubquery = Message::selectRaw('COUNT(*)')
            ->whereColumn('messages.chat_id', 'chats.id')
            ->where('sender_id', '!=', $userId)
            ->where('status', '!=', 'read');

        $chatIds = ChatParticipant::where('user_id', $userId)->pluck('chat_id');

        $chats = Chat::whereIn('id', $chatIds)
            ->select(['id', 'type', 'created_at', 'updated_at'])
            ->with(['participants' => function ($query) use ($userId): void {
                $query->select(['id', 'chat_id', 'user_id'])
                    ->where('user_id', '!=', $userId)
                    ->with('user:id,name,avatar');
            }])
            ->addSelect([
                'latest_message_text' => $latestMessageSubquery,
                'latest_message_at' => $latestMessageTimeSubquery,
                'unread_count' => $unreadCountSubquery,
            ])
            ->orderByDesc(DB::raw('(SELECT created_at FROM messages WHERE messages.chat_id = chats.id ORDER BY created_at DESC, id DESC LIMIT 1)'))
            ->get();

        $formatted = $chats->map(function (Chat $chat): array {
            $otherParticipant = $chat->participants->first();

            return [
                'id' => $chat->id,
                'type' => $chat->type,
                'participant' => $otherParticipant?->user ? [
                    'id' => $otherParticipant->user->id,
                    'name' => $otherParticipant->user->name,
                    'avatar' => $otherParticipant->user->avatar ?? null,
                ] : null,
                'last_message' => $chat->latest_message_text ? [
                    'text' => $chat->latest_message_text,
                    'created_at' => $chat->latest_message_at,
                ] : null,
                'unread_count' => (int) $chat->unread_count,
                'updated_at' => $chat->updated_at,
            ];
        })->values();

        return response()->json([
            'inbox' => $formatted,
        ], 200);
    }
}
