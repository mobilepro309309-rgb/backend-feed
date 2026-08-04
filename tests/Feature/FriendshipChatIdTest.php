<?php

namespace Tests\Feature;

use App\Models\{Chat, ChatParticipant, Friendship, User};
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendshipChatIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_friend_request_assigns_a_chat_and_exposes_it_in_friend_list(): void
    {
        $sender = User::factory()->create(['role' => 'student']);
        $receiver = User::factory()->create(['role' => 'student']);

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

        $senderToken = $sender->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $senderToken)
            ->postJson('/api/friends/send', [
                'receiver_id' => $receiver->id,
            ]);

        $response->assertCreated();

        $friendship = Friendship::query()
            ->where('sender_id', $sender->id)
            ->where('receiver_id', $receiver->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $this->assertNotNull($friendship->chat_id);
        $this->assertNotNull(Chat::find($friendship->chat_id));
        $this->assertSame(2, ChatParticipant::where('chat_id', $friendship->chat_id)->count());

        $receiverToken = $receiver->createToken('test-token')->plainTextToken;

        $friendsResponse = $this->withHeader('Authorization', 'Bearer ' . $receiverToken)
            ->getJson('/api/friends?type=colleagues');

        $friendsResponse->assertOk();

        $friendData = collect($friendsResponse->json('data'))
            ->firstWhere('id', $sender->id);

        $this->assertNotNull($friendData);
        $this->assertSame((int) $friendship->chat_id, (int) $friendData['chat_id']);
    }
}
