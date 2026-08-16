<?php

namespace App\Http\Controllers\Api\Challenges;

use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use App\Http\Controllers\Controller;
use App\Models\Challenges\DailyChallenge;
use App\Models\Message;
use App\Models\QuestionExplanation;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\QuizAccessService;
use Illuminate\Support\Facades\Log;

class DailyChallengeController extends Controller
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
            DailyChallenge::query()->with('user'),
            $request
        )->latest('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $items->map(function (DailyChallenge $item) use ($user): array {
                $options = collect($item->options ?? [])->map(function ($opt): array {
                    return ['label' => (string) $opt, 'value' => (string) $opt];
                })->values()->all();

                $correctIndex = $item->correct_answer_index ?? null;
                $correctValue = null;
                if (is_numeric($correctIndex) && isset($options[(int) $correctIndex])) {
                    $correctValue = $options[(int) $correctIndex]['value'];
                }

                $quizData = [
                    'id' => $item->id,
                    'title' => $item->title,
                    'subject' => $item->subject,
                    'school_grade' => $item->school_grade,
                    'status' => $item->status ?? 'draft',
                    'badge_text' => $item->badge_text,
                    'file_url' => $item->file_url ?? null,
                    'explanation_video_url' => $item->explanation?->video_url ?? null,
                    'prompt' => $item->prompt,
                    'question' => $item->prompt,
                    'description' => $item->prompt,
                    'options' => $options,
                    'choices' => $options,
                    'correct_answer' => $correctValue,
                    'correct_answer_index' => is_numeric($correctIndex) ? (int) $correctIndex : null,
                    'correctIndex' => is_numeric($correctIndex) ? (int) $correctIndex : null,
                    'correct_index' => is_numeric($correctIndex) ? (int) $correctIndex : null,
                    'reward_text' => $item->reward_text,
                    'quizType' => 'daily_challenge',
                    'questionType' => 'daily_challenge',
                    'type' => 'daily_challenge',
                    'user' => [
                        'id' => $item->user?->id,
                        'name' => $item->user?->name,
                    ],
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];

                // Append access_rules for the quiz
                if ($user) {
                    $quizData['access_rules'] = $this->quizAccessService->buildAccessRulesObject('daily_challenge', $user);
                }

                return $quizData;
            })->values(),
        ]);
    }

    protected function resolveQuizId(string $input): ?int
    {
        if (is_numeric($input)) {
            $candidate = (int) $input;
            $message = Message::find($candidate);

            if ($message) {
                $messageIdCandidates = [];
                $messageText = (string) ($message->text ?? '');
                if ($messageText !== '') {
                    $messageIdCandidates[] = $messageText;
                }

                foreach ($messageIdCandidates as $candidateText) {
                    if (is_numeric($candidateText)) {
                        return (int) $candidateText;
                    }

                    $decodedText = json_decode($candidateText, true);
                    $payloadId = data_get($decodedText, 'sharedCardPayload.id')
                        ?? data_get($decodedText, 'sharedCardPayload.quiz_id')
                        ?? data_get($decodedText, 'shared_card_payload.id')
                        ?? data_get($decodedText, 'shared_card_payload.quiz_id')
                        ?? data_get($decodedText, 'quiz_id')
                        ?? data_get($decodedText, 'id');

                    if (is_numeric($payloadId)) {
                        return (int) $payloadId;
                    }
                }
            }

            return $candidate;
        }

        return null;
    }

    public function show(string $id)
    {
        $resolvedQuizId = $this->resolveQuizId($id);
        $item = $resolvedQuizId ? DailyChallenge::with('explanation')->find($resolvedQuizId) : null;

        if (! $item) {
            return response()->json(['message' => 'التحدي اليومي غير موجود'], 404);
        }

        $options = collect($item->options ?? [])->map(function ($opt) {
            return ['label' => (string) $opt, 'value' => (string) $opt];
        })->values()->all();

        $correctIndex = $item->correct_answer_index ?? null;
        $correctValue = null;
        if (is_numeric($correctIndex) && isset($options[(int) $correctIndex])) {
            $correctValue = $options[(int) $correctIndex]['value'];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $item->id,
                'title' => $item->title,
                'subject' => $item->subject,
                'prompt' => $item->prompt,
                'question' => $item->prompt,
                'options' => $options,
                'choices' => $options,
                'correct_answer' => $correctValue,
                'correct_answer_index' => is_numeric($correctIndex) ? (int) $correctIndex : null,
                'correctIndex' => is_numeric($correctIndex) ? (int) $correctIndex : null,
                'correct_index' => is_numeric($correctIndex) ? (int) $correctIndex : null,
                'badge_text' => $item->badge_text,
                'file_url' => $item->file_url ?? null,
                'explanation_video_url' => $item->explanation?->video_url ?? null,
                'reward_text' => $item->reward_text,
                'quizType' => 'daily_challenge',
                'questionType' => 'daily_challenge',
                'type' => 'daily_challenge',
            ],
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
                'message' => 'ليس لديك صلاحية لحفظ هذا التحدي اليومي',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subject' => ['required', 'string', 'max:120'],
            'school_grade' => ['nullable', 'string'],
            'term' => ['nullable', 'in:1,2'],
            'prompt' => ['nullable', 'string'],
            'options' => ['required', 'array', 'min:2'],
            'options.*' => ['nullable', 'string'],
            'correct_answer_index' => ['required', 'integer', 'min:0', 'max:3'],
            'badge_text' => ['nullable', 'string', 'max:120'],
            'file_url' => ['nullable', 'string', 'max:2048'],
            'explanation_video_url' => ['nullable', 'string', 'max:2048'],
            'reward_text' => ['nullable', 'string', 'max:180'],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $validated['school_grade'] = $request->input('school_grade', $user->school_grade ?? null);
        $validated['term'] = $request->input('term', $user->term ?? 1);

        $prompt = trim((string) ($validated['prompt'] ?? ''));
        $fileUrl = trim((string) ($validated['file_url'] ?? ''));

        if ($prompt === '' && $fileUrl === '') {
            return response()->json([
                'message' => 'يجب إدخال نص السؤال أو رفع ملف للسؤال.',
                'errors' => [
                    'prompt' => ['يجب إدخال نص السؤال أو رفع ملف للسؤال.'],
                ],
            ], 422);
        }

        $challenge = DailyChallenge::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'school_grade' => $validated['school_grade'] ?? null,
            'term' => (int) ($validated['term'] ?? 1),
            'prompt' => $prompt ?: null,
            'file_url' => $validated['file_url'] ?? null,
            'options' => array_values(array_map(fn($value) => (string) $value, $validated['options'])),
            'correct_answer_index' => (int) $validated['correct_answer_index'],
            'badge_text' => $validated['badge_text'] ?? null,
            'reward_text' => $validated['reward_text'] ?? null,
            'expires_in_hours' => (int) ($validated['expires_in_hours'] ?? 24),
            'status' => $validated['status'] ?? 'draft',
            'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
        ]);

        QuestionExplanation::upsertForQuestion($challenge, $request->input('explanation_video_url'), $user->id);

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
                        'تمت إضافة تحدي يومي جديد!',
                        "تمت إضافة تحدي يومي جديد: {$challenge->title}",
                        [
                            'type' => 'new_daily_challenge',
                            'challenge_id' => $challenge->id,
                            'target_id' => $challenge->id,
                            'target_type' => 'quiz',
                            'title' => $challenge->title,
                            'subject' => $challenge->subject,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('[DailyChallengeController] Failed to send push notification to student', [
                        'recipient_id' => $recipient->id,
                        'challenge_id' => $challenge->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[DailyChallengeController] Failed to dispatch notifications after challenge save', [
                'challenge_id' => $challenge->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'تم حفظ التحدي اليومي بنجاح',
            'data' => $challenge->fresh()->load('user'),
        ], 201);
    }

    /**
     * Return all active/published daily challenges in a front-end friendly format.
     */
    public function getAllChallenges()
    {
        // Fetch the latest created published challenge
        // Pick the latest created challenge regardless of status to be more forgiving
        $item = DailyChallenge::orderBy('created_at', 'desc')->first();

        if (! $item) {
            return response()->json([
                'status' => 'success',
                'challenges' => [],
            ]);
        }

        $now = Carbon::now();
        $publishedAt = $item->published_at;
        // If published_at missing, fall back to created_at
        $publishedAt = $item->published_at ?: $item->created_at;
        $expiresAt = $publishedAt->copy()->addHours((int) ($item->expires_in_hours ?? 24));
        if (! $now->lessThan($expiresAt)) {
            return response()->json([
                'status' => 'success',
                'challenges' => [],
            ]);
        }

        $options = collect($item->options ?? [])->map(function ($opt) {
            return [
                'label' => (string) $opt,
                'value' => (string) $opt,
            ];
        })->values()->all();

        $correctIndex = $item->correct_answer_index ?? null;
        $correctValue = null;
        if (is_numeric($correctIndex) && isset($options[(int) $correctIndex])) {
            $correctValue = $options[(int) $correctIndex]['value'];
        }

        $mapped = [
            'id' => $item->id,
            'title' => $item->title,
            'subject' => $item->subject,
            'prompt' => $item->prompt,
            'options' => $options,
            'correct_answer' => $correctValue,
            'file_url' => $item->file_url ?? null,
            'badge_text' => $item->badge_text,
            'reward_text' => $item->reward_text,
            'published_at' => $publishedAt->toDateTimeString(),
            'expires_in_hours' => $item->expires_in_hours,
            'expires_at' => $expiresAt->toDateTimeString(),
            'user' => $item->user ? ['id' => $item->user->id, 'name' => $item->user->name] : null,
        ];

        return response()->json([
            'status' => 'success',
            'challenges' => [$mapped],
        ]);
    }

    /**
     * Return the latest active challenge (single item) or null if none.
     */
    public function getLatestChallenge()
    {
        $item = DailyChallenge::orderBy('created_at', 'desc')->first();

        if (! $item) {
            return response()->json(['status' => 'success', 'challenge' => null]);
        }

        $publishedAt = $item->published_at ?: $item->created_at;
        $now = Carbon::now();
        $expiresAt = $publishedAt->copy()->addHours((int) ($item->expires_in_hours ?? 24));

        if (! $now->lessThan($expiresAt)) {
            return response()->json(['status' => 'success', 'challenge' => null]);
        }

        $options = collect($item->options ?? [])->map(function ($opt) {
            return ['label' => (string) $opt, 'value' => (string) $opt];
        })->values()->all();

        $correctIndex = $item->correct_answer_index ?? null;
        $correctValue = null;
        if (is_numeric($correctIndex) && isset($options[(int) $correctIndex])) {
            $correctValue = $options[(int) $correctIndex]['value'];
        }

        $mapped = [
            'id' => $item->id,
            'title' => $item->title,
            'subject' => $item->subject,
            'prompt' => $item->prompt,
            'options' => $options,
            'correct_answer' => $correctValue,
            'correct_answer_index' => is_numeric($correctIndex) ? (int) $correctIndex : null,
            'correctIndex' => is_numeric($correctIndex) ? (int) $correctIndex : null,
            'correct_index' => is_numeric($correctIndex) ? (int) $correctIndex : null,
            'badge_text' => $item->badge_text,
            'file_url' => $item->file_url ?? null,
            'reward_text' => $item->reward_text,
            'published_at' => $publishedAt->toDateTimeString(),
            'expires_in_hours' => $item->expires_in_hours,
            'expires_at' => $expiresAt->toDateTimeString(),
            'user' => $item->user ? ['id' => $item->user->id, 'name' => $item->user->name] : null,
        ];

        return response()->json(['status' => 'success', 'challenge' => $mapped]);
    }
}
