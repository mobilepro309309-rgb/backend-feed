<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TypingStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_active_typing_status_for_chat_participants(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        $chat = Chat::create(['type' => 'private']);
        ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $sender->id]);
        ChatParticipant::create(['chat_id' => $chat->id, 'user_id' => $receiver->id]);

        Cache::put("chat_typing:{$chat->id}", [
            $sender->id => [
                'user_id' => $sender->id,
                'is_typing' => true,
                'timestamp' => now()->toISOString(),
            ],
        ], now()->addSeconds(5));

        $response = $this->actingAs($sender, 'sanctum')->getJson("/api/chats/{$chat->id}/typing");

        $response->assertOk()
            ->assertJsonPath('data.is_typing', true)
            ->assertJsonPath('data.typing_users.0', $sender->id);
    }
}
