<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LiveDuelChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_live_duel_challenge(): void
    {
        $user = User::factory()->create([
            'role' => 'question_post_admin',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/live-duel-challenges', [
                'title' => 'تحدي مواجهة مباشر',
                'subject' => 'رياضيات',
                'challenge_text' => 'اختر الإجابة الصحيحة بسرعة',
                'badge_text' => 'سريع',
                'question_count' => 3,
                'seconds_per_question' => 10,
                'questions' => [
                    [
                        'prompt' => 'كم عدد الأشكال في الصورة؟',
                        'options' => ['1', '2', '3', '4'],
                        'correctIndex' => 2,
                    ],
                    [
                        'prompt' => 'ما هو لون السماء؟',
                        'options' => ['أحمر', 'أخضر', 'أزرق', 'أصفر'],
                        'correctIndex' => 2,
                    ],
                    [
                        'prompt' => 'كم يبلغ مجموع 2 + 2؟',
                        'options' => ['1', '2', '3', '4'],
                        'correctIndex' => 3,
                    ],
                ],
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('live_duel_challenges', [
            'title' => 'تحدي مواجهة مباشر',
            'subject' => 'رياضيات',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('feeds', [
            'feedable_type' => 'App\\Models\\Challenges\\LiveDuelChallenge',
            'status' => 'draft',
        ]);
    }

    public function test_it_can_store_per_question_attachments_and_uploaded_files(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'role' => 'question_post_admin',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;
        $uploadedFile = UploadedFile::fake()->image('sample-image.png');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/live-duel-challenges', [
                'title' => 'تحدي مرفقات فردية',
                'subject' => 'علوم',
                'challenge_text' => 'اختر الإجابة الصحيحة',
                'badge_text' => 'مرفق',
                'question_count' => 2,
                'seconds_per_question' => 10,
                'status' => 'draft',
                'questions' => [
                    [
                        'prompt' => 'ما هذا الشكل؟',
                        'options' => ['1', '2', '3', '4'],
                        'correctIndex' => 1,
                        'attachment' => 'https://cdn.example.com/question-1.png',
                    ],
                    [
                        'prompt' => '',
                        'options' => ['1', '2', '3', '4'],
                        'correctIndex' => 2,
                        'attachment_file' => $uploadedFile,
                    ],
                ],
            ]);

        $response->assertCreated();

        $challenge = $response->json('data');
        $questions = $challenge['questions'] ?? [];

        $this->assertSame('https://cdn.example.com/question-1.png', $questions[0]['attachment'] ?? null);
        $this->assertNotEmpty($questions[1]['attachment'] ?? null);
        $this->assertTrue(Storage::disk('public')->exists($questions[1]['attachment'] ?? ''));
    }

    public function test_it_sends_push_notifications_to_registered_students_when_live_duel_is_created(): void
    {
        $admin = User::factory()->create([
            'role' => 'question_post_admin',
        ]);
        $recipient = User::factory()->create([
            'role' => 'student',
        ]);
        $recipient->devices()->create([
            'fcm_token' => 'live-duel-device-token',
            'device_type' => 'android',
        ]);

        $fakeNotificationService = new class extends NotificationService {
            public array $calls = [];

            public function __construct()
            {
            }

            public function sendNotification(User $user, string $title, string $body, array $data = []): array
            {
                $this->calls[] = [
                    'user_id' => $user->id,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                ];

                return ['success' => true];
            }
        };

        $this->app->instance(NotificationService::class, $fakeNotificationService);

        $token = $admin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/live-duel-challenges', [
                'title' => 'مبارزة إشعار',
                'subject' => 'رياضيات',
                'challenge_text' => 'اختر الإجابة الصحيحة بسرعة',
                'badge_text' => 'سريع',
                'question_count' => 3,
                'seconds_per_question' => 10,
                'questions' => [
                    [
                        'prompt' => 'كم عدد الأشكال في الصورة؟',
                        'options' => ['1', '2', '3', '4'],
                        'correctIndex' => 2,
                    ],
                    [
                        'prompt' => 'ما هو لون السماء؟',
                        'options' => ['أحمر', 'أخضر', 'أزرق', 'أصفر'],
                        'correctIndex' => 2,
                    ],
                    [
                        'prompt' => 'كم يبلغ مجموع 2 + 2؟',
                        'options' => ['1', '2', '3', '4'],
                        'correctIndex' => 3,
                    ],
                ],
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertCount(1, $fakeNotificationService->calls);
        $this->assertSame($recipient->id, $fakeNotificationService->calls[0]['user_id']);
        $this->assertSame('new_live_duel', $fakeNotificationService->calls[0]['data']['type']);
    }
}
