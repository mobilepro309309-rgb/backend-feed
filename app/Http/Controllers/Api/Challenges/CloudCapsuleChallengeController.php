<?php

namespace App\Http\Controllers\Api\Challenges;

use App\Http\Controllers\Controller;
use App\Models\Challenges\CloudCapsuleChallenge;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CloudCapsuleChallengeController extends Controller
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
                'message' => 'ليس لديك صلاحية لحفظ هذه الكبسولة',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subject' => ['required', 'string', 'max:120'],
            'intro_text' => ['nullable', 'string'],
            'file_url' => ['nullable', 'string', 'max:2048'],
            'badge_text' => ['nullable', 'string', 'max:120'],
            'reveal_text' => ['required', 'string'],
            'tip_text' => ['nullable', 'string'],
            'mood_text' => ['nullable', 'string'],
            'reveal_label' => ['nullable', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $challenge = new CloudCapsuleChallenge();
        $challenge->user_id = $user->id;
        $challenge->title = $validated['title'];
        $challenge->subject = $validated['subject'];
        $challenge->intro_text = $validated['intro_text'] ?? null;
        $challenge->file_url = $validated['file_url'] ?? null;
        $challenge->badge_text = $validated['badge_text'] ?? null;
        $challenge->reveal_text = $validated['reveal_text'];
        $challenge->tip_text = $validated['tip_text'] ?? null;
        $challenge->mood_text = $validated['mood_text'] ?? null;
        $challenge->reveal_label = $validated['reveal_label'] ?? null;
        $challenge->icon = $validated['icon'] ?? 'cloud';
        $challenge->status = $validated['status'] ?? 'draft';
        $challenge->published_at = ($validated['status'] ?? 'draft') === 'published' ? now() : null;
        $challenge->save();

        try {
            $recipients = User::whereHas('devices')->get();

            foreach ($recipients as $recipient) {
                try {
                    $this->notificationService->sendNotification(
                        $recipient,
                        'تمت إضافة كبسولة جديدة!',
                        "تمت إضافة كبسولة جديدة: {$challenge->title}",
                        [
                            'type' => 'new_cloud_capsule',
                            'capsule_id' => $challenge->id,
                            'target_id' => $challenge->id,
                            'target_type' => 'quiz',
                            'title' => $challenge->title,
                            'subject' => $challenge->subject,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('[CloudCapsuleChallengeController] Failed to send push notification to recipient', [
                        'recipient_id' => $recipient->id,
                        'capsule_id' => $challenge->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[CloudCapsuleChallengeController] Failed to dispatch notifications after capsule save', [
                'capsule_id' => $challenge->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'تم حفظ الكبسولة بنجاح',
            'data' => [
                'id' => $challenge->id,
                'title' => $challenge->title,
                'subject' => $challenge->subject,
                'file_url' => $challenge->file_url ?? null,
                'status' => $challenge->status,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
            ],
        ], 201);
    }
}
