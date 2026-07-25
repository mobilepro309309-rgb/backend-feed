<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendshipNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_friendship_request_notifies_the_receiver(): void
    {
        $sender = User::factory()->create([
            'role' => 'student',
        ]);
        $receiver = User::factory()->create([
            'role' => 'student',
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

        $token = $sender->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/friends/send', [
                'receiver_id' => $receiver->id,
            ]);

        $response->assertCreated();
        $this->assertCount(1, $fakeNotificationService->calls);
        $this->assertSame($receiver->id, $fakeNotificationService->calls[0]['user_id']);
        $this->assertSame('طلب زمالة جديد', $fakeNotificationService->calls[0]['title']);
        $this->assertSame('friend_request', $fakeNotificationService->calls[0]['data']['type']);
        $this->assertSame('friends', $fakeNotificationService->calls[0]['data']['target_type']);
    }
}
