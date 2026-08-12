<?php

namespace Tests\Unit;

use App\Services\CloudflareR2StorageService;
use Tests\TestCase;

class CloudflareR2StorageServiceTest extends TestCase
{
    public function test_get_public_url_strips_bucket_prefix_and_joins_domain(): void
    {
        config()->set('services.cloudflare_r2.public_url', 'https://pub.example.r2.dev');

        $service = new CloudflareR2StorageService();

        $this->assertSame(
            'https://pub.example.r2.dev/uploads/posts/photo.jpg',
            $service->getPublicUrl('public-assets/uploads/posts/photo.jpg')
        );

        $this->assertSame(
            'https://pub.example.r2.dev/uploads/posts/photo.jpg',
            $service->getPublicUrl('uploads/posts/photo.jpg')
        );
    }
}
