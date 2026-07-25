<?php

namespace Tests\Feature;

use App\Http\Controllers\ChatController;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatParticipantCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_or_create_chat_adds_both_participants(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        $this->actingAs($sender, 'sanctum');

        $response = $this->postJson('/api/chats/resolve-or-create', [
            'receiver_id' => $receiver->id,
        ]);

        $response->assertOk();

        $chatId = (int) $response->json('chat_id');
        $participantUserIds = ChatParticipant::where('chat_id', $chatId)
            ->pluck('user_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$sender->id, $receiver->id], $participantUserIds);
    }

    public function test_chat_controller_can_create_private_chat_with_two_participants_for_friendship_pair(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        $controller = new ChatController();
        $chat = $controller->ensurePrivateChatForFriendshipPair($sender->id, $receiver->id);

        $this->assertNotNull($chat);
        $this->assertSame('private', $chat->type);

        $participantUserIds = ChatParticipant::where('chat_id', $chat->id)
            ->pluck('user_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$sender->id, $receiver->id], $participantUserIds);
    }
}
