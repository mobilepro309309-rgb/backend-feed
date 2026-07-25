<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComparisonChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_comparison_challenge(): void
    {
        $user = User::factory()->create([
            'role' => 'question_post_admin',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/comparison-challenges', [
                'title' => 'مقارنة اختبار',
                'subject' => 'علوم',
                'left_label' => 'اليمين',
                'right_label' => 'الشمال',
                'left_text' => 'المحتوى الأول',
                'right_text' => 'المحتوى الثاني',
                'explanation' => 'شرح مختصر',
                'badge_text' => 'انقر للتقليب',
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('comparison_challenges', [
            'title' => 'مقارنة اختبار',
            'subject' => 'علوم',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('feeds', [
            'feedable_type' => 'App\\Models\\Challenges\\ComparisonChallenge',
            'status' => 'draft',
        ]);
    }

    public function test_it_sends_push_notifications_to_registered_students_when_comparison_is_created(): void
    {
        $admin = User::factory()->create([
            'role' => 'question_post_admin',
        ]);
        $recipient = User::factory()->create([
            'role' => 'student',
        ]);
        $recipient->devices()->create([
            'fcm_token' => 'comparison-device-token',
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
            ->postJson('/api/comparison-challenges', [
                'title' => 'مقارنة إشعار',
                'subject' => 'علوم',
                'left_label' => 'اليمين',
                'right_label' => 'الشمال',
                'left_text' => 'المحتوى الأول',
                'right_text' => 'المحتوى الثاني',
                'explanation' => 'شرح مختصر',
                'badge_text' => 'انقر للتقليب',
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertCount(1, $fakeNotificationService->calls);
        $this->assertSame($recipient->id, $fakeNotificationService->calls[0]['user_id']);
        $this->assertSame('new_comparison', $fakeNotificationService->calls[0]['data']['type']);
    }
}
