<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatParticipant;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendshipTeacherResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_for_user_returns_existing_chat_id_when_friendship_exists(): void
    {
        $user = User::factory()->create();
        $teacher = User::factory()->create(['role' => 'reply_questions_admin']);
        $chat = Chat::create(['type' => 'private']);

        ChatParticipant::insert([
            ['chat_id' => $chat->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
            ['chat_id' => $chat->id, 'user_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $friendship = Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $teacher->id,
            'status' => 'accepted',
            'chat_id' => $chat->id,
        ]);

        $response = $this->actingAs($user)->postJson('/api/friends/resolve-for-user', [
            'other_user_id' => $teacher->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('found', true);
        $response->assertJsonPath('chat_id', $chat->id);
        $response->assertJsonPath('friendship_id', $friendship->id);
        $response->assertJsonPath('friendship_status', 'accepted');
    }

    public function test_resolve_for_user_creates_chat_when_friendship_has_no_chat_id(): void
    {
        $user = User::factory()->create();
        $teacher = User::factory()->create(['role' => 'reply_questions_admin']);

        $friendship = Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $teacher->id,
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($user)->postJson('/api/friends/resolve-for-user', [
            'other_user_id' => $teacher->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('found', true);
        $response->assertJsonPath('friendship_status', 'accepted');
        $this->assertNotNull($response->json('chat_id'));
        $this->assertDatabaseHas('friendships', ['id' => $friendship->id, 'chat_id' => $response->json('chat_id')]);
    }
}
