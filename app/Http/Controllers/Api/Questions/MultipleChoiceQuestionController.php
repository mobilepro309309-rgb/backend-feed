<?php

namespace App\Http\Controllers\Api\Questions;

use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Questions\MultipleChoiceQuestion;
use App\Models\User;
use App\Services\NotificationService;

class MultipleChoiceQuestionController extends Controller
{
    public function __construct(protected NotificationService $notificationService)
    {
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
        $item = $resolvedQuizId ? MultipleChoiceQuestion::find($resolvedQuizId) : null;

        if (! $item) {
            return response()->json(['message' => 'السؤال غير موجود'], 404);
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
                'question' => $item->question,
                'prompt' => $item->question,
                'options' => $options,
                'choices' => $options,
                'correct_index' => (int) ($item->correct_index ?? 0),
                'correct_answer_index' => (int) ($item->correct_index ?? 0),
                'correctIndex' => (int) ($item->correct_index ?? 0),
                'badge_text' => $item->badge_text,
                'quizType' => 'multiple_choice',
                'questionType' => 'multiple_choice',
                'type' => 'multiple_choice',
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

        if (! in_array($user->role, ['main-admin', 'question_post_admin'], true)) {
            return response()->json([
                'message' => 'ليس لديك صلاحية لحفظ هذا السؤال',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subject' => ['required', 'string', 'max:120'],
            'question' => ['required', 'string'],
            'options' => ['required', 'array', 'min:2'],
            'options.*' => ['nullable', 'string'],
            'correct_index' => ['required', 'integer', 'min:0', 'max:3'],
            'badge_text' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $question = MultipleChoiceQuestion::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'question' => $validated['question'],
            'options' => array_values(array_map(fn($value) => (string) $value, $validated['options'])),
            'correct_index' => (int) $validated['correct_index'],
            'badge_text' => $validated['badge_text'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
        ]);

        try {
            $recipients = User::whereHas('devices')->get();

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
