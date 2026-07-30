<?php

namespace App\Http\Controllers\Api\Questions;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Questions\TrueFalseQuestion;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TrueFalseQuestionController extends Controller
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
        $item = $resolvedQuizId ? TrueFalseQuestion::find($resolvedQuizId) : null;

        if (! $item) {
            return response()->json(['message' => 'السؤال غير موجود'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $item->id,
                'title' => $item->title,
                'subject' => $item->subject,
                'prompt' => $item->prompt,
                'question' => $item->prompt,
                'file_url' => $item->file_url ?? null,
                'correct_answer' => (bool) $item->correct_answer,
                'correctAnswer' => (bool) $item->correct_answer,
                'explanation' => $item->explanation,
                'badge_text' => $item->badge_text,
                'quizType' => 'true_false',
                'questionType' => 'true_false',
                'type' => 'true_false',
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
            'prompt' => ['nullable', 'string'],
            'correct_answer' => ['required', 'boolean'],
            'explanation' => ['nullable', 'string'],
            'badge_text' => ['nullable', 'string', 'max:120'],
            'file_url' => ['nullable', 'string', 'max:2048'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

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
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'prompt' => $prompt ?: null,
            'file_url' => $validated['file_url'] ?? null,
            'correct_answer' => $normalizedCorrectAnswer,
            'explanation' => $validated['explanation'] ?? null,
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
