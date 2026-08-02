<?php

namespace Tests\Unit;

use App\Http\Resources\Posts\PostResource;
use App\Models\Posts\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class PostResourceTest extends TestCase
{
    public function test_it_includes_author_role_and_gender_in_user_payload(): void
    {
        $post = new class extends Post {
            public function getLikesAttribute(): int
            {
                return 0;
            }

            public function getCommentsAttribute(): int
            {
                return 0;
            }

            public function getSharesAttribute(): int
            {
                return 0;
            }
        };

        $post->forceFill([
            'id' => 1,
            'user_id' => 2,
            'subject' => 'Subject',
            'content' => 'Content',
            'status' => 'published',
        ]);

        $user = new User();
        $user->forceFill([
            'id' => 2,
            'name' => 'Author',
            'avatar' => 'https://example.com/avatar.png',
            'role' => 'user',
            'gender' => 'ولد',
            'school_grade' => 'ثالثة',
        ]);

        $post->setRelation('user', $user);

        $resource = new PostResource($post);
        $payload = $resource->toArray(new Request());

        $this->assertSame('user', $payload['user']['role']);
        $this->assertSame('ولد', $payload['user']['gender']);
        $this->assertSame('ثالثة', $payload['user']['grade']);
    }
}
