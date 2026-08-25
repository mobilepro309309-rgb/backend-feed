<?php

namespace Tests\Feature;

use App\Models\Posts\Post;
use App\Models\Grade;
use App\Models\Stage;
use App\Models\Track;
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

    public function test_feed_matches_the_complete_educational_structure(): void
    {
        $stage = Stage::create(['code' => 'secondary', 'name_ar' => 'ثانوي', 'name_en' => 'Secondary']);
        $grade = Grade::create(['stage_id' => $stage->id, 'code' => 'grade-1', 'name_ar' => 'الأول', 'name_en' => 'Grade 1']);
        $matchingTrack = Track::create(['grade_id' => $grade->id, 'code' => 'science', 'name_ar' => 'علوم', 'name_en' => 'Science']);
        $otherTrack = Track::create(['grade_id' => $grade->id, 'code' => 'arts', 'name_ar' => 'أدبي', 'name_en' => 'Arts']);

        $viewer = User::factory()->create([
            'role' => 'user',
            'stage_id' => $stage->id,
            'grade_id' => $grade->id,
            'track_id' => $matchingTrack->id,
            'specialized_subject_id' => null,
        ]);
        $matchingAuthor = User::factory()->create($viewer->only(['stage_id', 'grade_id', 'track_id', 'specialized_subject_id']));
        $differentAuthor = User::factory()->create([
            'stage_id' => $stage->id,
            'grade_id' => $grade->id,
            'track_id' => $otherTrack->id,
            'specialized_subject_id' => null,
        ]);

        Post::create(['user_id' => $matchingAuthor->id, 'content' => 'matching structure', 'subject' => 'science', 'status' => 'published']);
        Post::create(['user_id' => $differentAuthor->id, 'content' => 'different track', 'subject' => 'arts', 'status' => 'published']);

        $response = $this->actingAs($viewer, 'sanctum')->getJson('/api/posts');

        $response->assertOk()
            ->assertJsonFragment(['content' => 'matching structure'])
            ->assertJsonMissing(['content' => 'different track']);
    }

    public function test_post_author_can_see_their_own_pending_post(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        Post::create([
            'user_id' => $user->id,
            'content' => 'my pending post',
            'subject' => 'رياضيات',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/posts');

        $response->assertOk()->assertJsonFragment(['content' => 'my pending post']);
    }
}
