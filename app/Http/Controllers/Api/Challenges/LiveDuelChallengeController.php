<?php

namespace App\Http\Controllers\Api\Challenges;

use Illuminate\Support\Facades\{DB, Log, Schema};
use Illuminate\Http\{Request, UploadedFile};

use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use App\Http\Controllers\Controller;
use App\Events\{DuelInvitedEvent, DuelJoinedEvent};
use App\Models\Challenges\{DuelParticipant, DuelRoom, LiveDuelChallenge};
use App\Models\QuestionExplanation;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\QuizAccessService;

class LiveDuelChallengeController extends Controller
{
    use FiltersQuestionListings;

    public function __construct(
        protected NotificationService $notificationService,
        protected QuizAccessService $quizAccessService,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $items = $this->applyQuestionListingFilters(
            LiveDuelChallenge::query()->with(['user', 'explanation']),
            $request
        )->latest('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $items->map(function (LiveDuelChallenge $item) use ($user): array {
                $questions = collect($item->questions ?? [])->values()->all();
                $questionCount = (int) ($item->question_count ?? count($questions));

                $quizData = [
                    'id' => $item->id,
                    'title' => $item->title,
                    'subject' => $item->subject,
                    'school_grade' => $item->school_grade,
                    'status' => $item->status ?? 'draft',
                    'badge_text' => $item->badge_text,
                    'file_url' => $item->file_url ?? null,
                    'explanation_video_url' => $item->explanation?->video_url ?? null,
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

                // Append access_rules for the quiz
                if ($user) {
                    $quizData['access_rules'] = $this->quizAccessService->buildAccessRulesObject('live_duel', $user);
                }

                return $quizData;
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
        } catch (\Throwable $e) {
            Log::warning('[LiveDuel] Failed to queue invite notification: ' . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم إنشاء غرفة التحدي بنجاح',
            'room' => $room,
        ]);
    }

    public function joinRoom(Request $request)
    {
        $roomId = $request->input('room_id') ?? $request->input('roomId') ?? $request->input('id');

        if (! $roomId) {
            return response()->json(['message' => 'رقم الغرفة مطلوب'], 422);
        }

        $userId = auth()->id() ?? $request->user()?->id;

        // Perform transactional update on SQL database directly
        $updated = DB::transaction(function () use ($roomId, $userId) {
            $affected = DB::table('duel_rooms')
                ->where('id', $roomId)
                ->update([
                    'opponent_id' => $userId,
                    'status' => 'active',
                    'started_at' => now(),
                    'updated_at' => now(),
                ]);

            // Update participant status for joiner
            DB::table('duel_participants')
                ->where('room_id', $roomId)
                ->where('user_id', $userId)
                ->update(['status' => 'ready']);

            return $affected;
        });

        Log::info("🔥 [JOIN ROOM HARD UPDATE] Room {$roomId} joined by User {$userId}. Affected rows: {$updated}");

        // Fetch fresh Eloquent model for broadcasting and response
        $room = DuelRoom::with(['challenge', 'creator', 'opponent'])->find($roomId);

        if (! $room) {
            return response()->json(['message' => 'الغرفة غير موجودة'], 404);
        }

        // Broadcast WebSocket Event with ACTIVE room
        try {
            broadcast(new DuelJoinedEvent($room))->toOthers();
        } catch (\Exception $e) {
            Log::error('Broadcast error in joinRoom: ' . $e->getMessage());
        }

        $questions = $room->challenge->questions ?? [];

        return response()->json([
            'status' => 'active',
            'room_id' => (int) $room->id,
            'started_at' => $room->started_at ? $room->started_at->toIso8601String() : now()->toIso8601String(),
            'questions' => $questions,
            'room' => $room,
        ], 200)->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function getRoomStatus($roomId)
    {
        // Direct Query Builder SQL Execution bypassing Eloquent models/caches
        $room = DB::table('duel_rooms')->where('id', $roomId)->first();

        if (! $room) {
            return response()->json(['message' => 'الغرفة غير موجودة'], 404);
        }

        $questions = [];
        if (! empty($room->challenge_id)) {
            $challenge = DB::table('live_duel_challenges')->where('id', $room->challenge_id)->first();
            if ($challenge && ! empty($challenge->questions)) {
                $questions = is_string($challenge->questions) 
                    ? json_decode($challenge->questions, true) 
                    : $challenge->questions;
            }
        }

        Log::info("📡 [POLL STATUS HARD CHECK] Requested Room ID: {$roomId} | Direct DB Raw Status: '{$room->status}' | StartedAt: '{$room->started_at}'");

        header('Cache-Control', 'no-cache, no-store, must-revalidate');

        return response()->json([
            'room_id' => (int) $room->id,
            'status' => (string) $room->status, // Guaranteed raw DB string ('active' or 'waiting')
            'started_at' => $room->started_at ? \Carbon\Carbon::parse($room->started_at)->toIso8601String() : null,
            'questions' => $questions,
        ]);
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
            'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
            'challenge_text' => ['nullable', 'string'],
            'badge_text' => ['nullable', 'string', 'max:80'],
            'file_url' => ['nullable', 'string', 'max:2048'],
            'explanation_video_url' => ['nullable', 'string', 'max:2048'],
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
        $validated['unit_number'] = $request->input('unit_number', $validated['unit_number'] ?? null);

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
            'unit_number' => isset($validated['unit_number']) && $validated['unit_number'] !== '' ? (int) $validated['unit_number'] : null,
            'challenge_text' => $validated['challenge_text'] ?? null,
            'file_url' => $validated['file_url'] ?? null,
            'badge_text' => $validated['badge_text'] ?? null,
            'question_count' => (int) $validated['question_count'],
            'seconds_per_question' => (int) $validated['seconds_per_question'],
            'questions' => $validated['questions'],
            'status' => $validated['status'] ?? 'draft',
            'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
        ]);

        QuestionExplanation::upsertForQuestion($challenge, $request->input('explanation_video_url'), $user->id);

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