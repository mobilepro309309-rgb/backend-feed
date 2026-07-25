<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaServeEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_can_be_served_through_api_endpoint(): void
    {
        $user = User::factory()->create([
            'role' => 'main-admin',
        ]);

        $path = 'uploads/profile/avatar-test.txt';
        Storage::disk('project_local')->put($path, 'hello-from-media');

        $media = Media::create([
            'attachable_id' => $user->id,
            'attachable_type' => User::class,
            'file_path' => $path,
            'file_name' => 'avatar-test.txt',
            'file_type' => 'text/plain',
            'file_size' => 19,
        ]);

        $response = $this->actingAs($user)->getJson('/api/media/' . $media->id);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/plain; charset=UTF-8');
        $response->assertSeeText('hello-from-media');
    }

    public function test_me_returns_avatar_url_for_the_actual_avatar_media_record(): void
    {
        $user = User::factory()->create([
            'role' => 'main-admin',
        ]);

        $avatarMedia = Media::create([
            'attachable_id' => $user->id,
            'attachable_type' => User::class,
            'file_path' => 'uploads/user/avatar/2026/07/1/avatar.webp',
            'file_name' => 'avatar.webp',
            'file_type' => 'image/webp',
            'file_size' => 1234,
        ]);

        Media::create([
            'attachable_id' => $user->id,
            'attachable_type' => User::class,
            'file_path' => 'uploads/user/profile/2026/07/1/profile.webp',
            'file_name' => 'profile.webp',
            'file_type' => 'image/webp',
            'file_size' => 1234,
        ]);

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('user.avatar_url', url('/api/media/' . $avatarMedia->id));
    }
}
