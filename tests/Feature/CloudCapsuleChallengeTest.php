<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudCapsuleChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_cloud_capsule_challenge(): void
    {
        $user = User::factory()->create([
            'role' => 'question_post_admin',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/cloud-capsule-challenges', [
                'title' => 'كبسولة اختبار',
                'subject' => 'علوم',
                'intro_text' => 'مقدمة اختبار',
                'badge_text' => 'سر مختصر',
                'reveal_text' => 'السر هنا',
                'tip_text' => 'تلميح اختبار',
                'mood_text' => 'مخفي داخل السحابة',
                'reveal_label' => 'السر اللي ظهر',
                'icon' => 'cloud',
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('cloud_capsule_challenges', [
            'title' => 'كبسولة اختبار',
            'subject' => 'علوم',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('feeds', [
            'feedable_type' => 'App\\Models\\Challenges\\CloudCapsuleChallenge',
            'status' => 'draft',
        ]);
    }

    public function test_it_sends_push_notifications_to_registered_devices_when_capsule_is_created(): void
    {
        $admin = User::factory()->create([
            'role' => 'question_post_admin',
        ]);
        $recipient = User::factory()->create([
            'role' => 'student',
        ]);
        $recipient->devices()->create([
            'fcm_token' => 'test-device-token',
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
            ->postJson('/api/cloud-capsule-challenges', [
                'title' => 'كبسولة إشعار',
                'subject' => 'علوم',
                'intro_text' => 'مقدمة إشعار',
                'badge_text' => 'سر مختصر',
                'reveal_text' => 'السر هنا',
                'tip_text' => 'تلميح إشعار',
                'mood_text' => 'مخفي داخل السحابة',
                'reveal_label' => 'السر اللي ظهر',
                'icon' => 'cloud',
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertCount(1, $fakeNotificationService->calls);
        $this->assertSame($recipient->id, $fakeNotificationService->calls[0]['user_id']);
        $this->assertSame('تمت إضافة كبسولة جديدة!', $fakeNotificationService->calls[0]['title']);
        $this->assertSame('new_cloud_capsule', $fakeNotificationService->calls[0]['data']['type']);
    }
}
