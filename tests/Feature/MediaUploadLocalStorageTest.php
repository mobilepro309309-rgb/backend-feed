<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaUploadLocalStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_upload_uses_configured_project_disk(): void
    {
        Storage::fake('project_local');

        $user = User::factory()->create([
            'role' => 'main-admin',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/media', [
                'attachable_type' => User::class,
                'attachable_id' => $user->id,
                'collection' => 'avatar',
                'file' => UploadedFile::fake()->create('avatar.jpg', 120, 'image/jpeg'),
            ]);

        $response->assertCreated();

        $media = Media::query()->latest()->first();
        $this->assertNotNull($media);
        Storage::disk('project_local')->assertExists($media->file_path);
    }
}
