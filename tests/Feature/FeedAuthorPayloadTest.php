<?php

namespace Tests\Feature;

use App\Models\Posts\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedAuthorPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_posts_expose_author_role_and_gender(): void
    {
        $user = User::factory()->create([
            'name' => 'Author Name',
            'role' => 'user',
            'gender' => 'ولد',
            'avatar' => 'https://example.com/avatar.jpg',
        ]);

        Post::create([
            'user_id' => $user->id,
            'content' => 'مرحبا من التغذية',
            'subject' => 'تغذية',
            'status' => 'published',
        ]);

        $response = $this->getJson('/api/feed');

        $response->assertOk()
            ->assertJsonPath('data.0.details.type', 'post')
            ->assertJsonPath('data.0.details.user.id', $user->id)
            ->assertJsonPath('data.0.details.user.role', 'user')
            ->assertJsonPath('data.0.details.user.gender', 'ولد');
    }

    public function test_feed_filters_posts_by_auth_user_grade_for_non_admins(): void
    {
        $viewer = User::factory()->create([
            'role' => 'user',
            'school_grade' => '1',
        ]);

        $matchingAuthor = User::factory()->create([
            'role' => 'user',
            'school_grade' => '1',
        ]);

        $differentAuthor = User::factory()->create([
            'role' => 'user',
            'school_grade' => '2',
        ]);

        Post::create([
            'user_id' => $matchingAuthor->id,
            'content' => 'same grade post',
            'subject' => 'same',
            'status' => 'published',
        ]);

        Post::create([
            'user_id' => $differentAuthor->id,
            'content' => 'different grade post',
            'subject' => 'different',
            'status' => 'published',
        ]);

        $this->actingAs($viewer);

        $response = $this->getJson('/api/feed');

        $response->assertOk();
        $response->assertJsonMissing(['content' => 'different grade post']);
        $response->assertJsonFragment(['content' => 'same grade post']);
    }

    public function test_feed_injects_explanation_video_url_for_question_and_challenge_models(): void
    {
        $teacher = User::factory()->create([
            'role' => 'question_post_admin',
            'name' => 'Teacher One',
        ]);

        $trueFalseQuestion = \App\Models\Questions\TrueFalseQuestion::create([
            'user_id' => $teacher->id,
            'title' => 'True false explanatory video',
            'subject' => 'علوم',
            'school_grade' => '1',
            'term' => '1',
            'prompt' => 'The sky is blue.',
            'correct_answer' => true,
            'badge_text' => 'صح أم خطأ',
            'status' => 'published',
        ]);

        \App\Models\QuestionExplanation::create([
            'question_type' => \App\Models\Questions\TrueFalseQuestion::class,
            'question_id' => $trueFalseQuestion->id,
            'video_url' => 'https://example.com/true-false.mp4',
            'teacher_id' => $teacher->id,
        ]);

        $comparisonChallenge = \App\Models\Challenges\ComparisonChallenge::create([
            'user_id' => $teacher->id,
            'title' => 'Comparison explanatory video',
            'subject' => 'علوم',
            'school_grade' => '1',
            'term' => '1',
            'left_label' => 'اليسار',
            'right_label' => 'اليمين',
            'left_text' => 'A',
            'right_text' => 'B',
            'badge_text' => 'مقارنة',
            'status' => 'published',
        ]);

        \App\Models\QuestionExplanation::create([
            'question_type' => \App\Models\Challenges\ComparisonChallenge::class,
            'question_id' => $comparisonChallenge->id,
            'video_url' => 'https://example.com/comparison.mp4',
            'teacher_id' => $teacher->id,
        ]);

        $response = $this->getJson('/api/feed');

        $response->assertOk()
            ->assertJsonFragment(['explanation_video_url' => 'https://example.com/true-false.mp4'])
            ->assertJsonFragment(['explanation_video_url' => 'https://example.com/comparison.mp4']);
    }
}
