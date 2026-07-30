<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_and_create_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/user/profile');

        $response->assertOk();
        $response->assertJsonPath('profile.user_id', $user->id);
        $response->assertJsonPath('profile.theme_mode', 'light');

        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id]);
    }

    public function test_authenticated_user_can_update_profile(): void
    {
        $user = User::factory()->create();
        $user->profile()->create([
            'theme_mode' => 'light',
            'settings' => ['language' => 'ar'],
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/user/profile', [
            'avatar_url' => 'https://example.com/avatar.png',
            'theme_mode' => 'dark',
            'settings' => ['language' => 'en'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('profile.avatar_url', 'https://example.com/avatar.png');
        $response->assertJsonPath('profile.theme_mode', 'dark');
        $response->assertJsonPath('profile.settings.language', 'en');
    }
}
