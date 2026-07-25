<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindTheBugChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_find_the_bug_challenge(): void
    {
        $user = User::factory()->create([
            'role' => 'question_post_admin',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/find-the-bug-challenges', [
                'title' => 'اكتشف الخطأ اختبار',
                'subject' => 'علوم',
                'prompt' => 'هناك خطأ في الكود التالي',
                'options' => ['خيار 1', 'خيار 2', 'خيار 3', 'خيار 4'],
                'correct_answer_index' => 2,
                'badge_text' => 'اكتشف الخطأ',
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('find_the_bug_challenges', [
            'title' => 'اكتشف الخطأ اختبار',
            'subject' => 'علوم',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('feeds', [
            'feedable_type' => 'App\\Models\\Challenges\\FindTheBugChallenge',
            'status' => 'draft',
        ]);
    }

    public function test_it_sends_push_notifications_to_registered_students_when_find_the_bug_is_created(): void
    {
        $admin = User::factory()->create([
            'role' => 'question_post_admin',
        ]);
        $recipient = User::factory()->create([
            'role' => 'student',
        ]);
        $recipient->devices()->create([
            'fcm_token' => 'find-bug-device-token',
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
            ->postJson('/api/find-the-bug-challenges', [
                'title' => 'اكتشف الخطأ إشعار',
                'subject' => 'علوم',
                'prompt' => 'هناك خطأ في الكود التالي',
                'options' => ['خيار 1', 'خيار 2', 'خيار 3', 'خيار 4'],
                'correct_answer_index' => 2,
                'badge_text' => 'اكتشف الخطأ',
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertCount(1, $fakeNotificationService->calls);
        $this->assertSame($recipient->id, $fakeNotificationService->calls[0]['user_id']);
        $this->assertSame('new_find_the_bug', $fakeNotificationService->calls[0]['data']['type']);
    }
}
