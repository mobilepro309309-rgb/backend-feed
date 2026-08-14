<?php

namespace App\Http\Controllers\Api\Questions;

use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\QuestionExplanation;
use App\Models\Questions\MultipleChoiceQuestion;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\QuizAccessService;
use Illuminate\Support\Facades\Log;

class MultipleChoiceQuestionController extends Controller
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
            MultipleChoiceQuestion::query()->with(['user', 'explanation']),
            $request
        )->latest('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $items->map(function (MultipleChoiceQuestion $item) use ($user): array {
                $options = collect($item->options ?? [])->map(function ($opt): array {
                    return ['label' => (string) $opt, 'value' => (string) $opt];
                })->values()->all();

                $quizData = [
                    'id' => $item->id,
                    'title' => $item->title,
                    'subject' => $item->subject,
                    'school_grade' => $item->school_grade,
                    'status' => $item->status ?? 'draft',
                    'badge_text' => $item->badge_text,
                    'file_url' => $item->file_url ?? null,
                    'explanation_video_url' => $item->explanation?->video_url ?? null,
                    'prompt' => $item->question,
                    'question' => $item->question,
                    'description' => $item->question,
                    'options' => $options,
                    'choices' => $options,
                    'correct_index' => (int) ($item->correct_index ?? 0),
                    'correct_answer_index' => (int) ($item->correct_index ?? 0),
                    'correctIndex' => (int) ($item->correct_index ?? 0),
                    'quizType' => 'multiple_choice',
                    'questionType' => 'multiple_choice',
                    'type' => 'multiple_choice',
                    'user' => [
                        'id' => $item->user?->id,
                        'name' => $item->user?->name,
                    ],
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];

                // Append access_rules for the quiz
                if ($user) {
                    $quizData['access_rules'] = $this->quizAccessService->buildAccessRulesObject('multiple_choice', $user);
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
        $item = $resolvedQuizId ? MultipleChoiceQuestion::with('explanation')->find($resolvedQuizId) : null;

        if (! $item) {
            return response()->json(['message' => 'السؤال غير موجود'], 404);
        }

        $options = collect($item->options ?? [])->map(function ($opt) {
            return ['label' => (string) $opt, 'value' => (string) $opt];
        })->values()->all();

        $user = auth()->user();
        $quizData = [
            'id' => $item->id,
            'title' => $item->title,
            'subject' => $item->subject,
            'question' => $item->question,
            'prompt' => $item->question,
            'options' => $options,
            'choices' => $options,
            'correct_index' => (int) ($item->correct_index ?? 0),
            'correct_answer_index' => (int) ($item->correct_index ?? 0),
            'correctIndex' => (int) ($item->correct_index ?? 0),
            'badge_text' => $item->badge_text,
            'file_url' => $item->file_url ?? null,
            'explanation_video_url' => $item->explanation?->video_url ?? null,
            'quizType' => 'multiple_choice',
            'questionType' => 'multiple_choice',
            'type' => 'multiple_choice',
        ];

        // Append access_rules if user is authenticated
        if ($user) {
            $quizData['access_rules'] = $this->quizAccessService->buildAccessRulesObject('multiple_choice', $user);
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
            'question' => ['nullable', 'string'],
            'options' => ['required', 'array', 'min:2'],
            'options.*' => ['nullable', 'string'],
            'correct_index' => ['required', 'integer', 'min:0', 'max:3'],
            'badge_text' => ['nullable', 'string', 'max:120'],
            'file_url' => ['nullable', 'string', 'max:2048'],
            'explanation_video_url' => ['nullable', 'string', 'max:2048'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $validated['school_grade'] = $request->input('school_grade', $user->school_grade ?? null);
        $validated['term'] = $request->input('term', $user->term ?? 1);

        $questionText = trim((string) ($validated['question'] ?? ''));
        $fileUrl = trim((string) ($validated['file_url'] ?? ''));

        if ($questionText === '' && $fileUrl === '') {
            return response()->json([
                'message' => 'يجب إدخال نص السؤال أو رفع ملف للسؤال.',
                'errors' => [
                    'question' => ['يجب إدخال نص السؤال أو رفع ملف للسؤال.'],
                ],
            ], 422);
        }

        $question = MultipleChoiceQuestion::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'school_grade' => $validated['school_grade'] ?? null,
            'term' => (int) ($validated['term'] ?? 1),
            'question' => $questionText ?: null,
            'file_url' => $validated['file_url'] ?? null,
            'options' => array_values(array_map(fn($value) => (string) $value, $validated['options'])),
            'correct_index' => (int) $validated['correct_index'],
            'badge_text' => $validated['badge_text'] ?? null,
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
            $recipients = User::where('role', 'user')
                ->where('id', '!=', $question->user_id)
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
                        'سؤال جديد تم إضافته!',
                        'تم إضافة سؤال جديد في منصة التعليم، قم بحله الآن.',
                        [
                            'question_id' => $question->id,
                            'target_id' => $question->id,
                            'target_type' => 'quiz',
                            'type' => 'new_question',
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('[MultipleChoiceQuestionController] Failed to send push notification to student', [
                        'recipient_id' => $recipient->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[MultipleChoiceQuestionController] Failed to dispatch notifications after question save', [
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
