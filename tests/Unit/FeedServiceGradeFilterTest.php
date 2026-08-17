<?php

namespace Tests\Unit;

use App\Models\Feed;
use App\Models\Posts\Post;
use App\Models\User;
use App\Services\FeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedServiceGradeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_returns_posts_from_authors_with_the_same_grade_for_non_admins(): void
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

        $matchingPost = Post::create([
            'user_id' => $matchingAuthor->id,
            'content' => 'same grade post',
            'subject' => 'same',
            'status' => 'published',
        ]);
        Feed::create([
            'feedable_type' => Post::class,
            'feedable_id' => $matchingPost->id,
            'status' => 'published',
        ]);

        $differentPost = Post::create([
            'user_id' => $differentAuthor->id,
            'content' => 'different grade post',
            'subject' => 'different',
            'status' => 'published',
        ]);
        Feed::create([
            'feedable_type' => Post::class,
            'feedable_id' => $differentPost->id,
            'status' => 'published',
        ]);

        $this->actingAs($viewer);

        $service = app(FeedService::class);
        $feed = $service->getPaginatedFeed(10);
        $contents = $feed->getCollection()->pluck('feedable.content')->all();

        $this->assertContains('same grade post', $contents);
        $this->assertNotContains('different grade post', $contents);
    }

    public function test_it_excludes_draft_items_from_student_feed_results(): void
    {
        $viewer = User::factory()->create([
            'role' => 'user',
            'school_grade' => '1',
        ]);

        $matchingAuthor = User::factory()->create([
            'role' => 'user',
            'school_grade' => '1',
        ]);

        $publishedPost = Post::create([
            'user_id' => $matchingAuthor->id,
            'content' => 'published post',
            'subject' => 'same',
            'status' => 'published',
        ]);
        Feed::create([
            'feedable_type' => Post::class,
            'feedable_id' => $publishedPost->id,
            'status' => 'published',
        ]);

        $draftPost = Post::create([
            'user_id' => $matchingAuthor->id,
            'content' => 'draft post must be hidden',
            'subject' => 'same',
            'status' => 'draft',
        ]);
        Feed::create([
            'feedable_type' => Post::class,
            'feedable_id' => $draftPost->id,
            'status' => 'draft',
        ]);

        $this->actingAs($viewer);

        $service = app(FeedService::class);
        $feed = $service->getPaginatedFeed(10);
        $contents = $feed->getCollection()->pluck('feedable.content')->all();

        $this->assertContains('published post', $contents);
        $this->assertNotContains('draft post must be hidden', $contents);
    }

    public function test_it_applies_unit_filter_when_requested(): void
    {
        $viewer = User::factory()->create([
            'role' => 'user',
            'school_grade' => '1',
        ]);

        $matchingAuthor = User::factory()->create([
            'role' => 'user',
            'school_grade' => '1',
        ]);

        $postInUnitTwo = Post::create([
            'user_id' => $matchingAuthor->id,
            'content' => 'unit 2 post',
            'subject' => 'math',
            'unit_number' => 2,
            'status' => 'published',
        ]);
        Feed::create([
            'feedable_type' => Post::class,
            'feedable_id' => $postInUnitTwo->id,
            'status' => 'published',
        ]);

        $postInUnitThree = Post::create([
            'user_id' => $matchingAuthor->id,
            'content' => 'unit 3 post',
            'subject' => 'math',
            'unit_number' => 3,
            'status' => 'published',
        ]);
        Feed::create([
            'feedable_type' => Post::class,
            'feedable_id' => $postInUnitThree->id,
            'status' => 'published',
        ]);

        $this->actingAs($viewer);

        $service = app(FeedService::class);
        $feed = $service->getPaginatedFeed(10, 3);
        $contents = $feed->getCollection()->pluck('feedable.content')->all();

        $this->assertNotContains('unit 2 post', $contents);
        $this->assertContains('unit 3 post', $contents);
    }
}
