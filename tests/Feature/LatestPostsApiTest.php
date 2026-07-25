<?php

namespace Tests\Feature;

use App\Models\Posts\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LatestPostsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_latest_posts_for_profile_owner(): void
    {
        $user = User::factory()->create();

        Post::create([
            'user_id' => $user->id,
            'content' => 'آخر منشور للمستخدم',
            'subject' => 'علوم',
            'status' => 'published',
        ]);

        $response = $this->getJson('/api/users/' . $user->id . '/latest-posts');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'user',
                'posts',
            ])
            ->assertJsonCount(1, 'posts');
    }
}
