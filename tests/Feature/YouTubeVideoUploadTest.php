<?php

namespace Tests\Feature;

use Tests\TestCase;

class YouTubeVideoUploadTest extends TestCase
{
    public function test_upload_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/api/youtube/videos/upload', []);

        $response->assertStatus(401);
    }
}
