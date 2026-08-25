<?php

namespace App\Http\Controllers\Api\Questions;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use App\Models\Message;
use App\Models\QuestionExplanation;
use App\Models\Questions\TrueFalseQuestion;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\QuizAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TrueFalseQuestionController extends Controller
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
            TrueFalseQuestion::query()->with(['user', 'explanation']),
            $request
        )->latest('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $items->map(function (TrueFalseQuestion $item) use ($user): array {
                $quizData = [
                    'id' => $item->id,
                    'title' => $item->title,
                    'subject_id' => $item->subject_id,
                    'subject' => $item->subject,
                    'school_grade' => $item->school_grade,
                    'status' => $item->status ?? 'draft',
                    'badge_text' => $item->badge_text,
                    'file_url' => $item->file_url ?? null,
                    'explanation_video_url' => $item->explanation?->video_url ?? null,
                    'prompt' => $item->prompt,
                    'question' => $item->prompt,
                    'description' => $item->prompt,
                    'correct_answer' => (bool) $item->correct_answer,
                    'correctAnswer' => (bool) $item->correct_answer,
                    'quizType' => 'true_false',
                    'questionType' => 'true_false',
                    'type' => 'true_false',
                    'user' => [
                        'id' => $item->user?->id,
                        'name' => $item->user?->name,
                    ],
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];

                // Append access_rules for the quiz
                if ($user) {
                    $quizData['access_rules'] = $this->quizAccessService->buildAccessRulesObject('true_false', $user);
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
        $item = $resolvedQuizId ? TrueFalseQuestion::with('explanation')->find($resolvedQuizId) : null;

        if (! $item) {
            return response()->json(['message' => 'السؤال غير موجود'], 404);
        }

        $user = auth()->user();
        $quizData = [
            'id' => $item->id,
            'title' => $item->title,
            'subject' => $item->subject,
            'prompt' => $item->prompt,
            'question' => $item->prompt,
            'file_url' => $item->file_url ?? null,
            'explanation_video_url' => $item->explanation?->video_url ?? null,
            'correct_answer' => (bool) $item->correct_answer,
            'correctAnswer' => (bool) $item->correct_answer,
            'explanation' => $item->explanation,
            'badge_text' => $item->badge_text,
            'quizType' => 'true_false',
            'questionType' => 'true_false',
            'type' => 'true_false',
        ];

        // Append access_rules if user is authenticated
        if ($user) {
            $quizData['access_rules'] = $this->quizAccessService->buildAccessRulesObject('true_false', $user);
        }

        return response()->json([
            'status' => 'success',
            'data' => $quizData,
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

        if ((string) ($user->role ?? '') === 'user') {
            return response()->json([
                'message' => 'ليس لديك صلاحية لحفظ هذا السؤال',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'stage_id' => ['nullable', 'integer'],
            'grade_id' => ['nullable', 'integer'],
            'track_id' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:120'],
            'school_grade' => ['nullable', 'string'],
            'term' => ['nullable', 'in:1,2'],
            'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
            'prompt' => ['nullable', 'string'],
            'correct_answer' => ['required', 'boolean'],
            'explanation' => ['nullable', 'string'],
            'badge_text' => ['nullable', 'string', 'max:120'],
            'file_url' => ['nullable', 'string', 'max:2048'],
            'explanation_video_url' => ['nullable', 'string', 'max:2048'],
            'difficulty' => ['sometimes', 'string', 'in:easy,medium,hard'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $validated['school_grade'] = $request->input('school_grade', $user->school_grade ?? null);
        $validated['term'] = $request->input('term', $user->term ?? 1);
        $validated['unit_number'] = $request->input('unit_number', $validated['unit_number'] ?? null);

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

        $normalizedCorrectAnswer = $validated['correct_answer'] ? 0 : 1;

        $question = TrueFalseQuestion::create([
            'user_id' => $user->id,
            'subject_id' => (int) $validated['subject_id'],
            'stage_id' => $validated['stage_id'] ?? null,
            'grade_id' => $validated['grade_id'] ?? null,
            'track_id' => $validated['track_id'] ?? null,
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'school_grade' => $validated['school_grade'] ?? null,
            'term' => (int) ($validated['term'] ?? 1),
            'unit_number' => isset($validated['unit_number']) && $validated['unit_number'] !== '' ? (int) $validated['unit_number'] : null,
            'prompt' => $prompt ?: null,
            'file_url' => $validated['file_url'] ?? null,
            'correct_answer' => $normalizedCorrectAnswer,
            'explanation' => $validated['explanation'] ?? null,
            'badge_text' => $validated['badge_text'] ?? null,
            'difficulty' => $validated['difficulty'] ?? 'medium',
            'status' => $validated['status'] ?? 'draft',
            'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
        ]);

        QuestionExplanation::upsertForQuestion(
            $question,
            $request->input('explanation_video_url'),
            $user->id
        );

        try {
            $questionGrade = (string) ($question->school_grade ?? '');
            $recipients = User::where('id', '!=', $question->user_id)
                ->whereHas('devices')
                ->when($questionGrade !== '', function ($query) use ($questionGrade) {
                    $query->where(function ($gradeQuery) use ($questionGrade) {
                        $gradeQuery->where('school_grade', $questionGrade)
                            ->orWhere('school_grade', (int) $questionGrade);
                    });
                })
                ->get();

            foreach ($recipients as $recipient) {
                try {
                    $this->notificationService->sendNotification(
                        $recipient,
                        'تمت إضافة سؤال جديد!',
                        "تمت إضافة سؤال صح أم خطأ: {$question->title}",
                        [
                            'type' => 'new_true_false',
                            'question_id' => $question->id,
                            'target_id' => $question->id,
                            'target_type' => 'quiz',
                            'title' => $question->title,
                            'subject' => $question->subject,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('[TrueFalseQuestionController] Failed to send push notification to student', [
                        'recipient_id' => $recipient->id,
                        'question_id' => $question->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[TrueFalseQuestionController] Failed to dispatch notifications after question save', [
                'question_id' => $question->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'تم حفظ السؤال بنجاح',
            'data' => $question->fresh()->load('user'),
        ], 201);
    }
}
