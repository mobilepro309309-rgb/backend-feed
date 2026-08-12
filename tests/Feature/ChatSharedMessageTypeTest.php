<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class ChatSharedMessageTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_share_saves_feed_type_as_message_type(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        $response = $this->actingAs($sender)->postJson('/api/messages/initial-share', [
            'receiver_id' => $receiver->id,
            'text' => 'shared content',
            'feed_type' => 'daily-challenge',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message.message_type', 'DailyChallengeQuiz');
        $this->assertDatabaseHas('chats', ['type' => 'teach']);
    }

    public function test_initial_share_still_succeeds_when_broadcasting_fails(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        Broadcast::shouldReceive('event')->once()->andThrow(new \RuntimeException('broadcast failed'));

        $response = $this->actingAs($sender)->postJson('/api/messages/initial-share', [
            'receiver_id' => $receiver->id,
            'text' => 'shared content',
            'feed_type' => 'daily-challenge',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('messages', ['chat_id' => $response->json('chat_id')]);
    }

    public function test_assigning_reply_questions_admin_does_not_create_chat(): void
    {
        $actor = User::factory()->create(['role' => 'main-admin']);
        $target = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($actor)->postJson('/api/admin-roles', [
            'user_id' => $target->id,
            'role' => 'reply_questions_admin',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'reply_questions_admin']);
        $this->assertDatabaseMissing('chats', ['type' => 'teach']);
    }
}
