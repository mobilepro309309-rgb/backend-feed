<?php

namespace Tests\Feature;

use App\Models\Posts\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedPostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_posts_endpoint_returns_saved_posts_for_user(): void
    {
        $user = User::factory()->create();
        $post = Post::create([
            'user_id' => $user->id,
            'content' => 'منشور محفوظ للاختبار',
            'subject' => 'رياضيات',
            'status' => 'published',
        ]);

        $user->savedPosts()->attach($post->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/users/' . $user->id . '/saved-posts');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('posts.data.0.id', $post->id)
            ->assertJsonPath('posts.data.0.content', 'منشور محفوظ للاختبار');
    }
}
