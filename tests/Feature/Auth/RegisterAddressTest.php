<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\Users\UserAddress;
use App\Services\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DummyGeocodingService extends GeocodingService
{
    public function geocodeAddress(string $address): ?array
    {
        return null;
    }
}

class RegisterAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_address_with_new_fields(): void
    {
        $this->app->instance(GeocodingService::class, new DummyGeocodingService());

        $response = $this->postJson('/api/register', [
            'name' => 'أحمد',
            'phone' => '01000000000',
            'password' => 'password123',
            'governorate' => 'القاهرة',
            'district' => 'الجيزة',
            'village' => 'العمرانية',
            'latitude' => 30.0444,
            'longitude' => 31.2357,
        ]);

        $response->assertCreated();

        $user = User::latest()->firstOrFail();
        $address = UserAddress::where('user_id', $user->id)->first();

        $this->assertNotNull($address);
        $this->assertSame('القاهرة', $address->governorate);
        $this->assertSame('الجيزة', $address->city_or_center);
        $this->assertSame('العمرانية', $address->village_name);
        $this->assertSame(30.0444, $address->latitude);
        $this->assertSame(31.2357, $address->longitude);
    }
}
