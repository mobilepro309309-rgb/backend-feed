<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Log};

use App\Events\{MessageSent, MessageStatusUpdated, MessagesRead, UserTyping};
use App\Models\{Chat, ChatParticipant, Message};

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

        if ($existingChat) {
            return response()->json([
                'chat_id' => (int) $existingChat->id,
                'created' => false,
            ], 200);
        }

        return DB::transaction(function () use ($receiverId, $senderId) {
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

    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => ['nullable', 'integer', 'exists:users,id'],
            'text' => ['required', 'string', 'max:10000'],
            'chat_id' => ['nullable', 'integer', 'exists:chats,id'],
            'reply_to_id' => ['nullable', 'integer', 'exists:messages,id'],
            'message_type' => ['nullable', 'string'],
            'shared_content_id' => ['nullable', 'string'],
            'shared_content_type' => ['nullable', 'string'],
        ]);

        $senderId = (int) auth()->id();
        $receiverId = isset($validated['receiver_id']) ? (int) $validated['receiver_id'] : null;
        $chatId = $validated['chat_id'] ?? null;
        $resolvedMessageType = $this->resolveMessageType($request, $validated);
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

                        $message = Message::create([
                            'chat_id' => $chat->id,
                            'sender_id' => $senderId,
                            'text' => $resolvedMessageText,
                            'message_type' => $resolvedMessageType,
                            'status' => 'sent',
                            'reply_to_id' => $validated['reply_to_id'] ?? null,
                        ]);

                        DB::commit();

                        $chatId = (int) $chat->id;
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        Log::error('Chat message creation failed', [
                            'sender_id' => $senderId,
                            'receiver_id' => $receiverId,
                            'error' => $e->getMessage(),
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
                    ]);

                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    Log::error('Chat message save failed', [
                        'chat_id' => $chatId,
                        'sender_id' => $senderId,
                        'receiver_id' => $receiverId,
                        'error' => $e->getMessage(),
                    ]);

                    return response()->json([
                        'message' => 'Unable to save chat message.',
                    ], 500);
                }
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
            'text' => ['required', 'string', 'max:10000'],
            'message_type' => ['nullable', 'string'],
            'feed_type' => ['nullable', 'string'],
            'shared_content_id' => ['nullable', 'string'],
            'shared_content_type' => ['nullable', 'string'],
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
                $chatId = (int) $chat->id;
            }
        }

        $message = Message::create([
            'chat_id' => $chatId,
            'sender_id' => $senderId,
            'text' => $resolvedMessageText,
            'message_type' => $resolvedMessageType,
            'status' => 'sent',
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

            $mapped = match ($normalized) {
                'text' => 'text',
                'post' => 'post',
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

    public function getMessages($chatId)
    {
        \Log::emergency('API HIT: Request received for ChatID: ' . $chatId);

        $messages = \App\Models\Message::where('chat_id', $chatId)
            ->with(['replyTo' => function ($query) {
                $query->select(['id', 'chat_id', 'sender_id', 'text', 'created_at']);
            }])
            ->orderBy('created_at', 'asc')
            ->get();

        \Log::emergency('API RESULT: Prepared response for ChatID: ' . $chatId);

        return response()->json(['messages' => $messages], 200);
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
