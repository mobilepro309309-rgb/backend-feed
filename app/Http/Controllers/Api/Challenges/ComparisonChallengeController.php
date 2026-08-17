<?php

namespace App\Http\Controllers\Api\Challenges;

use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use App\Http\Controllers\Controller;
use App\Models\Challenges\ComparisonChallenge;
use App\Models\Message;
use App\Models\QuestionExplanation;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\QuizAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ComparisonChallengeController extends Controller
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
            ComparisonChallenge::query()->with('user'),
            $request
        )->latest('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $items->map(function (ComparisonChallenge $item) use ($user): array {
                $prompt = trim(($item->left_text ?? '') . ' vs ' . ($item->right_text ?? ''));

                $quizData = [
                    'id' => $item->id,
                    'title' => $item->title,
                    'subject' => $item->subject,
                    'school_grade' => $item->school_grade,
                    'status' => $item->status ?? 'draft',
                    'badge_text' => $item->badge_text,
                    'file_url' => $item->file_url ?? null,
                    'explanation_video_url' => $item->explanation?->video_url ?? null,
                    'prompt' => $prompt,
                    'question' => $prompt,
                    'description' => $item->explanation ?: $prompt,
                    'left_label' => $item->left_label,
                    'right_label' => $item->right_label,
                    'left_text' => $item->left_text,
                    'right_text' => $item->right_text,
                    'explanation' => $item->explanation,
                    'quizType' => 'comparison_card',
                    'questionType' => 'comparison_card',
                    'type' => 'comparison_card',
                    'user' => [
                        'id' => $item->user?->id,
                        'name' => $item->user?->name,
                    ],
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];

                // Append access_rules for the quiz
                if ($user) {
                    $quizData['access_rules'] = $this->quizAccessService->buildAccessRulesObject('comparison_card', $user);
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
        $item = $resolvedQuizId ? ComparisonChallenge::with('explanation')->find($resolvedQuizId) : null;

        if (! $item) {
            return response()->json(['message' => 'المقارنة غير موجودة'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $item->id,
                'title' => $item->title,
                'subject' => $item->subject,
                'left_label' => $item->left_label,
                'right_label' => $item->right_label,
                'left_text' => $item->left_text,
                'right_text' => $item->right_text,
                'prompt' => $item->left_text . ' vs ' . $item->right_text,
                'question' => $item->left_text . ' vs ' . $item->right_text,
                'explanation' => $item->explanation,
                'badge_text' => $item->badge_text,
                'file_url' => $item->file_url ?? null,
                'explanation_video_url' => $item->explanation?->video_url ?? null,
                'quizType' => 'comparison_card',
                'questionType' => 'comparison_card',
                'type' => 'comparison_card',
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
                'message' => 'ليس لديك صلاحية لحفظ هذه المقارنة',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subject' => ['required', 'string', 'max:120'],
            'school_grade' => ['nullable', 'string'],
            'term' => ['nullable', 'in:1,2'],
            'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
            'left_label' => ['nullable', 'string', 'max:80'],
            'right_label' => ['nullable', 'string', 'max:80'],
            'left_text' => ['required', 'string'],
            'right_text' => ['required', 'string'],
            'file_url' => ['nullable', 'string', 'max:2048'],
            'explanation_video_url' => ['nullable', 'string', 'max:2048'],
            'explanation' => ['nullable', 'string'],
            'badge_text' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $validated['school_grade'] = $request->input('school_grade', $user->school_grade ?? null);
        $validated['term'] = $request->input('term', $user->term ?? 1);
        $validated['unit_number'] = $request->input('unit_number', $validated['unit_number'] ?? null);

        $challenge = ComparisonChallenge::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'school_grade' => $validated['school_grade'] ?? null,
            'term' => (int) ($validated['term'] ?? 1),
            'unit_number' => isset($validated['unit_number']) && $validated['unit_number'] !== '' ? (int) $validated['unit_number'] : null,
            'left_label' => $validated['left_label'] ?? null,
            'right_label' => $validated['right_label'] ?? null,
            'left_text' => $validated['left_text'],
            'right_text' => $validated['right_text'],
            'file_url' => $validated['file_url'] ?? null,
            'explanation' => $validated['explanation'] ?? null,
            'badge_text' => $validated['badge_text'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
        ]);

        QuestionExplanation::upsertForQuestion($challenge, $request->input('explanation_video_url'), $user->id);

        try {
            $comparisonGrade = (string) ($challenge->school_grade ?? '');
            $recipients = User::query()
                ->where('id', '!=', $challenge->user_id)
                ->whereHas('devices')
                ->when($comparisonGrade !== '', function ($query) use ($comparisonGrade) {
                    $query->where(function ($gradeQuery) use ($comparisonGrade) {
                        $gradeQuery->where('school_grade', $comparisonGrade)
                            ->orWhere('school_grade', (int) $comparisonGrade);
                    });
                })
                ->get();

            foreach ($recipients as $recipient) {
                try {
                    $this->notificationService->sendNotification(
                        $recipient,
                        'تمت إضافة مقارنة جديدة!',
                        "تمت إضافة مقارنة جديدة: {$challenge->title}",
                        [
                            'type' => 'new_comparison',
                            'comparison_id' => $challenge->id,
                            'target_id' => $challenge->id,
                            'target_type' => 'quiz',
                            'title' => $challenge->title,
                            'subject' => $challenge->subject,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('[ComparisonChallengeController] Failed to send push notification to student', [
                        'recipient_id' => $recipient->id,
                        'comparison_id' => $challenge->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[ComparisonChallengeController] Failed to dispatch notifications after comparison save', [
                'comparison_id' => $challenge->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'تم حفظ المقارنة بنجاح',
            'data' => $challenge->fresh()->load('user'),
        ], 201);
    }
}
