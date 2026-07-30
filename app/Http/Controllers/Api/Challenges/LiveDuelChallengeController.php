<?php

namespace App\Http\Controllers\Api\Challenges;

use App\Http\Controllers\Controller;
use App\Models\Challenges\LiveDuelChallenge;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class LiveDuelChallengeController extends Controller
{
    public function __construct(protected NotificationService $notificationService)
    {
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
                'message' => 'ليس لديك صلاحية لحفظ هذا التحدي',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subject' => ['required', 'string', 'max:120'],
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
            $recipients = User::whereHas('devices')->get();

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
