<?php

namespace App\Http\Controllers\Api\Challenges;

use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use App\Http\Controllers\Controller;
use App\Models\Challenges\FindTheBugChallenge;
use App\Models\Message;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FindTheBugChallengeController extends Controller
{
    use FiltersQuestionListings;

    public function __construct(protected NotificationService $notificationService)
    {
    }

    public function index(Request $request)
    {
        $items = $this->applyQuestionListingFilters(
            FindTheBugChallenge::query()->with('user'),
            $request
        )->latest('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $items->map(function (FindTheBugChallenge $item): array {
                $options = collect($item->options ?? [])->map(function ($opt): array {
                    return ['label' => (string) $opt, 'value' => (string) $opt];
                })->values()->all();

                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'subject' => $item->subject,
                    'school_grade' => $item->school_grade,
                    'status' => $item->status ?? 'draft',
                    'badge_text' => $item->badge_text,
                    'file_url' => $item->file_url ?? null,
                    'prompt' => $item->prompt,
                    'question' => $item->prompt,
                    'description' => $item->prompt,
                    'options' => $options,
                    'choices' => $options,
                    'correct_answer_index' => (int) ($item->correct_answer_index ?? 0),
                    'correctIndex' => (int) ($item->correct_answer_index ?? 0),
                    'correct_index' => (int) ($item->correct_answer_index ?? 0),
                    'quizType' => 'find_the_bug',
                    'questionType' => 'find_the_bug',
                    'type' => 'find_the_bug',
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

    protected function resolveQuizId(string $input): ?int
    {
        if (is_numeric($input)) {
            $candidate = (int) $input;
            $message = Message::find($candidate);

            if ($message) {
                $messageText = (string) ($message->text ?? '');
                if (is_numeric($messageText)) {
                    return (int) $messageText;
                }

                $decodedText = json_decode($messageText, true);
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

            return $candidate;
        }

        return null;
    }

    public function show(string $id)
    {
        $resolvedQuizId = $this->resolveQuizId($id);
        $item = $resolvedQuizId ? FindTheBugChallenge::find($resolvedQuizId) : null;

        if (! $item) {
            return response()->json(['message' => 'سؤال اكتشاف الخطأ غير موجود'], 404);
        }

        $options = collect($item->options ?? [])->map(function ($opt) {
            return ['label' => (string) $opt, 'value' => (string) $opt];
        })->values()->all();

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
                'correct_answer_index' => (int) ($item->correct_answer_index ?? 0),
                'correctIndex' => (int) ($item->correct_answer_index ?? 0),
                'correct_index' => (int) ($item->correct_answer_index ?? 0),
                'badge_text' => $item->badge_text,
                'file_url' => $item->file_url ?? null,
                'quizType' => 'find_the_bug',
                'questionType' => 'find_the_bug',
                'type' => 'find_the_bug',
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
                'message' => 'ليس لديك صلاحية لحفظ هذا السؤال',
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

        $challenge = FindTheBugChallenge::create([
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
                        'تمت إضافة سؤال اكتشف الخطأ جديد!',
                        "تمت إضافة سؤال اكتشف الخطأ: {$challenge->title}",
                        [
                            'type' => 'new_find_the_bug',
                            'challenge_id' => $challenge->id,
                            'target_id' => $challenge->id,
                            'target_type' => 'quiz',
                            'title' => $challenge->title,
                            'subject' => $challenge->subject,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('[FindTheBugChallengeController] Failed to send push notification to student', [
                        'recipient_id' => $recipient->id,
                        'challenge_id' => $challenge->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[FindTheBugChallengeController] Failed to dispatch notifications after challenge save', [
                'challenge_id' => $challenge->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'تم حفظ سؤال اكتشف الخطأ بنجاح',
            'data' => $challenge->fresh()->load('user'),
        ], 201);
    }
}
