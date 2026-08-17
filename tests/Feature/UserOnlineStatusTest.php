<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UserOnlineStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_mark_themselves_online_via_ping(): void
    {
        $user = User::factory()->create([
            'is_online' => false,
            'last_seen' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/user/ping');

        $response->assertOk();
        $response->assertJsonPath('status', 'online');
        $response->assertJsonPath('online', true);

        $user->refresh();

        $this->assertTrue($user->isOnlineNow());
        $this->assertTrue(Cache::has($user->onlineCacheKey()));
        $this->assertNotNull($user->last_seen);
    }
}
