<?php

namespace App\Http\Controllers\Api\Challenges;

use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use App\Http\Controllers\Controller;
use App\Models\Challenges\CloudCapsuleChallenge;
use App\Models\QuestionExplanation;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CloudCapsuleChallengeController extends Controller
{
    use FiltersQuestionListings;

    public function __construct(protected NotificationService $notificationService)
    {
    }

    public function index(Request $request)
    {
        $items = $this->applyQuestionListingFilters(
            CloudCapsuleChallenge::query()->with(['user', 'explanation']),
            $request
        )->latest('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $items->map(function (CloudCapsuleChallenge $item): array {
                $prompt = trim((string) ($item->intro_text ?? ''));

                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'subject_id' => $item->subject_id,
                    'subject' => $item->subject,
                    'school_grade' => $item->school_grade,
                    'status' => $item->status ?? 'draft',
                    'badge_text' => $item->badge_text,
                    'file_url' => $item->file_url ?? null,
                    'explanation_video_url' => $item->explanation?->video_url ?? null,
                    'prompt' => $prompt,
                    'question' => $item->reveal_text,
                    'description' => $prompt ?: $item->reveal_text,
                    'intro_text' => $item->intro_text,
                    'reveal_text' => $item->reveal_text,
                    'tip_text' => $item->tip_text,
                    'mood_text' => $item->mood_text,
                    'reveal_label' => $item->reveal_label,
                    'icon' => $item->icon,
                    'quizType' => 'cloud_capsule',
                    'questionType' => 'cloud_capsule',
                    'type' => 'cloud_capsule',
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
                'message' => 'ليس لديك صلاحية لحفظ هذه الكبسولة',
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
            'intro_text' => ['nullable', 'string'],
            'file_url' => ['nullable', 'string', 'max:2048'],
            'explanation_video_url' => ['nullable', 'string', 'max:2048'],
            'badge_text' => ['nullable', 'string', 'max:120'],
            'reveal_text' => ['required', 'string'],
            'tip_text' => ['nullable', 'string'],
            'mood_text' => ['nullable', 'string'],
            'reveal_label' => ['nullable', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:50'],
            'difficulty' => ['sometimes', 'string', 'in:easy,medium,hard'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $validated['school_grade'] = $request->input('school_grade', $user->school_grade ?? null);
        $validated['term'] = $request->input('term', $user->term ?? 1);
        $validated['unit_number'] = $request->input('unit_number', $validated['unit_number'] ?? null);

        $challenge = new CloudCapsuleChallenge();
        $challenge->user_id = $user->id;
        $challenge->subject_id = (int) $validated['subject_id'];
        $challenge->stage_id = $validated['stage_id'] ?? null;
        $challenge->grade_id = $validated['grade_id'] ?? null;
        $challenge->track_id = $validated['track_id'] ?? null;
        $challenge->title = $validated['title'];
        $challenge->subject = $validated['subject'];
        $challenge->school_grade = $validated['school_grade'] ?? null;
        $challenge->term = (int) ($validated['term'] ?? 1);
        $challenge->unit_number = isset($validated['unit_number']) && $validated['unit_number'] !== '' ? (int) $validated['unit_number'] : null;
        $challenge->intro_text = $validated['intro_text'] ?? null;
        $challenge->file_url = $validated['file_url'] ?? null;
        $challenge->badge_text = $validated['badge_text'] ?? null;
        $challenge->reveal_text = $validated['reveal_text'];
        $challenge->tip_text = $validated['tip_text'] ?? null;
        $challenge->mood_text = $validated['mood_text'] ?? null;
        $challenge->reveal_label = $validated['reveal_label'] ?? null;
        $challenge->icon = $validated['icon'] ?? 'cloud';
        $challenge->difficulty = $validated['difficulty'] ?? 'medium';
        $challenge->status = $validated['status'] ?? 'draft';
        $challenge->published_at = ($validated['status'] ?? 'draft') === 'published' ? now() : null;
        $challenge->save();

        QuestionExplanation::upsertForQuestion($challenge, $request->input('explanation_video_url'), $user->id);

        try {
            $capsuleGrade = (string) ($challenge->school_grade ?? '');
            $recipients = User::query()
                ->where('id', '!=', $challenge->user_id)
                ->whereHas('devices')
                ->when($capsuleGrade !== '', function ($query) use ($capsuleGrade) {
                    $query->where(function ($gradeQuery) use ($capsuleGrade) {
                        $gradeQuery->where('school_grade', $capsuleGrade)
                            ->orWhere('school_grade', (int) $capsuleGrade);
                    });
                })
                ->get();

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
