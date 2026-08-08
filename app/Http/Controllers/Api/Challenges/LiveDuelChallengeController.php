<?php

namespace App\Http\Controllers\Api\Challenges;

use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use App\Http\Controllers\Controller;
use App\Events\DuelInvitedEvent;
use App\Events\DuelJoinedEvent;
use App\Models\Challenges\DuelParticipant;
use App\Models\Challenges\DuelRoom;
use App\Models\Challenges\LiveDuelChallenge;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class LiveDuelChallengeController extends Controller
{
    use FiltersQuestionListings;

    public function __construct(protected NotificationService $notificationService)
    {
    }

    public function index(Request $request)
    {
        $items = $this->applyQuestionListingFilters(
            LiveDuelChallenge::query()->with('user'),
            $request
        )->latest('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $items->map(function (LiveDuelChallenge $item): array {
                $questions = collect($item->questions ?? [])->values()->all();
                $questionCount = (int) ($item->question_count ?? count($questions));

                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'subject' => $item->subject,
                    'school_grade' => $item->school_grade,
                    'status' => $item->status ?? 'draft',
                    'badge_text' => $item->badge_text,
                    'file_url' => $item->file_url ?? null,
                    'prompt' => $item->challenge_text,
                    'question' => $item->challenge_text,
                    'description' => $item->challenge_text,
                    'challenge_text' => $item->challenge_text,
                    'question_count' => $questionCount,
                    'seconds_per_question' => (int) ($item->seconds_per_question ?? 0),
                    'questions' => $questions,
                    'quizType' => 'live_duel',
                    'questionType' => 'live_duel',
                    'type' => 'live_duel',
                    'user' => [
                        'id' => $item->user?->id,
                        'name' => $item->user?->name,
                    ],
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];
            })->values(),
        ]);
    }

    public function getEligiblePeers(Request $request)
    {
        try {
            $currentUser = auth()->user();
            if (! $currentUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $search = $request->query('search');
            $challengeId = $request->query('challenge_id');
            $userTable = (new User())->getTable();
            $hasRoleColumn = Schema::hasColumn($userTable, 'role');
            $hasSchoolGradeColumn = Schema::hasColumn($userTable, 'school_grade');
            $hasCreatedAtColumn = Schema::hasColumn($userTable, 'created_at');

            Log::debug('[LiveDuel] getEligiblePeers request', [
                'user_id' => $currentUser->id,
                'school_grade' => $currentUser->school_grade,
                'challenge_id' => $challengeId,
                'search' => $search,
                'has_role_column' => $hasRoleColumn,
                'has_school_grade_column' => $hasSchoolGradeColumn,
                'has_created_at_column' => $hasCreatedAtColumn,
            ]);

            $query = User::query()
                ->where('id', '!=', $currentUser->id);

            if ($hasRoleColumn) {
                $query->where('role', 'user');
            }

            if ($hasSchoolGradeColumn && $currentUser->school_grade !== null) {
                $query->where('school_grade', $currentUser->school_grade);
            }

            if ($search) {
                $query->where('name', 'LIKE', "%{$search}%");
            }

            if ($challengeId) {
                Log::debug('[LiveDuel] getEligiblePeers ignoring challenge_id filter', [
                    'challenge_id' => $challengeId,
                ]);
            }

            $selectColumns = ['id', 'name'];
            if ($hasSchoolGradeColumn) {
                $selectColumns[] = 'school_grade';
            }
            if ($hasCreatedAtColumn) {
                $selectColumns[] = 'created_at';
            }

            $peers = $query
                ->select($selectColumns)
                ->limit(20)
                ->get();

            Log::debug('[LiveDuel] getEligiblePeers result count', [
                'count' => $peers->count(),
                'peer_ids' => $peers->pluck('id')->all(),
            ]);

            return response()->json([
                'status' => 'success',
                'my_grade' => $currentUser->school_grade,
                'peers' => $peers,
            ]);
        } catch (\Throwable $exception) {
            Log::error('[LiveDuel] getEligiblePeers failed', [
                'message' => $exception->getMessage(),
                'line' => $exception->getLine(),
                'file' => $exception->getFile(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'حدث خطأ أثناء جلب الزملاء المتاحين. حاول مرة أخرى.',
            ], 500);
        }
    }

    public function createRoom(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'challenge_id' => ['required', 'exists:live_duel_challenges,id'],
            'opponent_id' => ['required', 'exists:users,id'],
        ]);

        if ((int) $validated['opponent_id'] === $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'لا يمكن دعوة نفسك إلى غرفة المبارزة',
            ], 422);
        }

        $room = DB::transaction(function () use ($validated, $user) {
            $room = DuelRoom::create([
                'challenge_id' => $validated['challenge_id'],
                'creator_id' => $user->id,
                'opponent_id' => $validated['opponent_id'],
                'status' => 'waiting',
            ]);

            DuelParticipant::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'status' => 'ready',
            ]);

            DuelParticipant::create([
                'room_id' => $room->id,
                'user_id' => $validated['opponent_id'],
                'status' => 'joined',
            ]);

            return $room;
        });

        $room->load(['challenge', 'creator', 'opponent', 'participants.user']);

        broadcast(new DuelInvitedEvent($room))->toOthers();

        try {
            $notificationResult = $this->notificationService->sendNotificationToUser(
                $validated['opponent_id'],
                [
                    'title' => 'تحدي جديد ⚔️',
                    'body' => "أرسل لك {$user->name} دعوة لمبارزة أذكياء!",
                    'type' => 'live_duel_invite',
                    'data' => [
                        'room_id' => $room->id,
                        'action' => 'open_duel_invite',
                        'challenge_id' => $room->challenge_id,
                    ],
                ]
            );
            Log::debug('[LiveDuel] Invite notification queued', [
                'room_id' => $room->id,
                'opponent_id' => $validated['opponent_id'],
                'notification' => $notificationResult,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[LiveDuel] Failed to queue invite notification', [
                'room_id' => $room->id,
                'opponent_id' => $validated['opponent_id'],
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم إنشاء غرفة التحدي بنجاح',
            'room' => $room,
        ]);
    }

    public function joinRoom(Request $request)
    {
        $roomId = $request->input('room_id');

        if (! $roomId) {
            return response()->json(['message' => 'Room ID is required'], 422);
        }

        $room = DuelRoom::find($roomId);

        if (! $room) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        // Explicitly update attributes
        $room->opponent_id = auth()->id();
        $room->status = 'active'; // Force DB state change
        $room->started_at = now();

        // Save to Database
        $saved = $room->save();

        Log::info("🔥 [JOIN ROOM DB CHECK] Room {$roomId} saved status: " . ($saved ? 'ACTIVE' : 'FAILED'));

        // Broadcast WebSocket Event
        try {
            broadcast(new DuelJoinedEvent($room));
        } catch (\Exception $e) {
            Log::error('Broadcast error in joinRoom: ' . $e->getMessage());
        }

        $room->load(['challenge']);

        return response()->json([
            'status' => 'active',
            'started_at' => $room->started_at->toIso8601String(),
            'questions' => $room->challenge->questions ?? [],
            'room' => $room,
        ], 200);
    }

    public function getRoomStatus($roomId)
    {
        // 1. STRICT QUERY: Fetch room specifically by its Primary Key ID
        $room = DuelRoom::where('id', $roomId)->first();

        if (! $room) {
            return response()->json(['message' => 'الغرفة غير موجودة'], 404);
        }

        // 2. Clear any model internal cache / refresh directly from Database table
        try {
            $room->refresh();
        } catch (\Throwable $e) {
            Log::warning("[LiveDuel] getRoomStatus refresh failed for room {$roomId}: " . $e->getMessage());
        }

        // 3. Load associated challenge for questions
        $room->load(['challenge']);

        Log::info("📡 [POLL STATUS CHECK] Requested Room ID: {$roomId} | Actual DB Status: {$room->status} \vert{} StartedAt: {$room->started_at}");

        return response()->json([
            'room_id' => (int) $room->id,
            'status' => (string) $room->status,
            'started_at' => $room->started_at ? $room->started_at->toIso8601String() : null,
            'questions' => $room->challenge->questions ?? [],
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        $subject = trim((string) $request->input('subject', ''));
        $schoolGrade = trim((string) $request->input('school_grade', $user->school_grade ?? ''));

        if (! in_array($user->role, ['main-admin', 'question_post_admin'], true)
            && ! $user->hasScope($schoolGrade, $subject, 'can_create_questions')) {
            return response()->json([
                'message' => 'ليس لديك صلاحية لحفظ هذا التحدي',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subject' => ['required', 'string', 'max:120'],
            'school_grade' => ['nullable', 'string'],
            'term' => ['nullable', 'in:1,2'],
            'challenge_text' => ['nullable', 'string'],
            'badge_text' => ['nullable', 'string', 'max:80'],
            'file_url' => ['nullable', 'string', 'max:2048'],
            'question_count' => ['required', 'integer', 'min:1', 'max:20'],
            'seconds_per_question' => ['required', 'integer', 'min:5', 'max:120'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.prompt' => ['nullable', 'string'],
            'questions.*.options' => ['required', 'array', 'size:4'],
            'questions.*.options.*' => ['nullable', 'string'],
            'questions.*.correctIndex' => ['required', 'integer', 'min:0', 'max:3'],
            'questions.*.image.uri' => ['nullable', 'string'],
            'questions.*.image.name' => ['nullable', 'string'],
            'questions.*.attachment' => ['nullable'],
            'questions.*.attachment_file' => ['nullable', 'file'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $validated['school_grade'] = $request->input('school_grade', $user->school_grade ?? null);
        $validated['term'] = $request->input('term', $user->term ?? 1);

        foreach ($validated['questions'] as $index => $question) {
            $prompt = trim((string) ($question['prompt'] ?? ''));
            $imageUri = trim((string) data_get($question, 'image.uri', ''));
            $attachment = $this->resolveQuestionAttachment($request, $question, $index);
            $validated['questions'][$index]['attachment'] = $attachment;

            if ($prompt === '' && $imageUri === '' && $attachment === null) {
                return response()->json([
                    'message' => 'يجب إدخال نص السؤال أو رفع صورة لكل سؤال',
                    'errors' => [
                        "questions.$index.prompt" => ['يجب إدخال نص السؤال أو رفع صورة'],
                    ],
                ], 422);
            }

            if ($prompt === '' && $imageUri !== '') {
                $validated['questions'][$index]['prompt'] = trim((string) data_get($question, 'image.name', 'صورة السؤال')) ?: 'صورة السؤال';
            }
        }

        $challenge = LiveDuelChallenge::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'school_grade' => $validated['school_grade'] ?? null,
            'term' => (int) ($validated['term'] ?? 1),
            'challenge_text' => $validated['challenge_text'] ?? null,
            'file_url' => $validated['file_url'] ?? null,
            'badge_text' => $validated['badge_text'] ?? null,
            'question_count' => (int) $validated['question_count'],
            'seconds_per_question' => (int) $validated['seconds_per_question'],
            'questions' => $validated['questions'],
            'status' => $validated['status'] ?? 'draft',
            'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
        ]);

        try {
            $challengeGrade = (string) ($challenge->school_grade ?? '');
            $recipients = User::where('role', 'user')
                ->where('id', '!=', $challenge->user_id)
                ->whereHas('devices')
                ->when($challengeGrade !== '', function ($query) use ($challengeGrade) {
                    $query->where(function ($gradeQuery) use ($challengeGrade) {
                        $gradeQuery->where('school_grade', $challengeGrade)
                            ->orWhere('school_grade', (int) $challengeGrade);
                    });
                })
                ->get();

            foreach ($recipients as $recipient) {
                try {
                    $this->notificationService->sendNotification(
                        $recipient,
                        'تمت إضافة مبارزة مباشرة جديدة!',
                        "تمت إضافة مبارزة مباشرة جديدة: {$challenge->title}",
                        [
                            'type' => 'new_live_duel',
                            'challenge_id' => $challenge->id,
                            'target_id' => $challenge->id,
                            'target_type' => 'quiz',
                            'title' => $challenge->title,
                            'subject' => $challenge->subject,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('[LiveDuelChallengeController] Failed to send push notification to student', [
                        'recipient_id' => $recipient->id,
                        'challenge_id' => $challenge->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[LiveDuelChallengeController] Failed to dispatch notifications after challenge save', [
                'challenge_id' => $challenge->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'تم حفظ التحدي بنجاح',
            'data' => $challenge->fresh()->load('user'),
        ], 201);
    }

    private function resolveQuestionAttachment(Request $request, array $question, int $index): ?string
    {
        $existingAttachment = trim((string) data_get($question, 'attachment', ''));
        if ($existingAttachment !== '') {
            return $existingAttachment;
        }

        foreach (["questions.{$index}.attachment", "questions.{$index}.attachment_file"] as $field) {
            $file = $request->file($field);

            if ($file instanceof UploadedFile && $file->isValid()) {
                $storedPath = $file->store('live-duel-attachments', 'public');

                return $storedPath ?: null;
            }
        }

        return null;
    }
}
