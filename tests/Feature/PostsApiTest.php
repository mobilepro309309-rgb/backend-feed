<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_posts(): void
    {
        $user = User::factory()->create();
        Post::create([
            'user_id' => $user->id,
            'content' => 'مرحبا بالعالم',
            'subject' => 'رياضيات',
            'status' => 'published',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/posts');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'content', 'subject', 'user' => ['id', 'name']],
                ],
            ])
            ->assertJsonPath('data.0.content', 'مرحبا بالعالم');
    }
}
