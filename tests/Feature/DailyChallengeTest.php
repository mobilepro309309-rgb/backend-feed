<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_daily_challenge(): void
    {
        $user = User::factory()->create([
            'role' => 'question_post_admin',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/daily-challenges', [
                'title' => 'تحدي يومي اختبار',
                'subject' => 'علوم',
                'prompt' => 'ما هي الشمس؟',
                'options' => ['كوكب', 'نجم', 'قمر', 'مجرة'],
                'correct_answer_index' => 1,
                'badge_text' => 'مثبت',
                'reward_text' => 'مكافأة اليوم',
                'expires_in_hours' => 24,
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('daily_challenges', [
            'title' => 'تحدي يومي اختبار',
            'subject' => 'علوم',
            'user_id' => $user->id,
        ]);
    }

    public function test_it_sends_push_notifications_to_registered_students_when_daily_challenge_is_created(): void
    {
        $admin = User::factory()->create([
            'role' => 'question_post_admin',
        ]);
        $recipient = User::factory()->create([
            'role' => 'student',
        ]);
        $recipient->devices()->create([
            'fcm_token' => 'daily-device-token',
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
            ->postJson('/api/daily-challenges', [
                'title' => 'تحدي إشعار',
                'subject' => 'علوم',
                'prompt' => 'ما هي الشمس؟',
                'options' => ['كوكب', 'نجم', 'قمر', 'مجرة'],
                'correct_answer_index' => 1,
                'badge_text' => 'مثبت',
                'reward_text' => 'مكافأة اليوم',
                'expires_in_hours' => 24,
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertCount(1, $fakeNotificationService->calls);
        $this->assertSame($recipient->id, $fakeNotificationService->calls[0]['user_id']);
        $this->assertSame('new_daily_challenge', $fakeNotificationService->calls[0]['data']['type']);
    }
}
