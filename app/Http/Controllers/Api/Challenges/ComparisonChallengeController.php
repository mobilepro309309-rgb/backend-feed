<?php

namespace App\Http\Controllers\Api\Challenges;

use App\Http\Controllers\Controller;
use App\Models\Challenges\ComparisonChallenge;
use App\Models\Message;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ComparisonChallengeController extends Controller
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
        $item = $resolvedQuizId ? ComparisonChallenge::find($resolvedQuizId) : null;

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

        if (! in_array($user->role, ['main-admin', 'question_post_admin'], true)) {
            return response()->json([
                'message' => 'ليس لديك صلاحية لحفظ هذه المقارنة',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subject' => ['required', 'string', 'max:120'],
            'left_label' => ['nullable', 'string', 'max:80'],
            'right_label' => ['nullable', 'string', 'max:80'],
            'left_text' => ['required', 'string'],
            'right_text' => ['required', 'string'],
            'file_url' => ['nullable', 'string', 'max:2048'],
            'explanation' => ['nullable', 'string'],
            'badge_text' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $challenge = ComparisonChallenge::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'subject' => $validated['subject'],
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

        try {
            $recipients = User::whereHas('devices')->get();

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
