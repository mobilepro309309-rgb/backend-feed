<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Users\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRegistrationStoresLocationFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_persists_selected_governorate_district_and_village(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'phone' => '01234567890',
            'password' => '12345678',
            'governorate' => 'القاهرة',
            'district' => 'المعادي',
            'village' => 'المعادي القديمة',
        ]);

        $response->assertCreated();

        $user = User::where('governorate', 'القاهرة')->where('district', 'المعادي')->where('village', 'المعادي القديمة')->first();
        $this->assertNotNull($user);
        $this->assertSame('01234567890', $user->phone);
        $this->assertNotNull($user->location);
        $this->assertNotNull($user->latitude);
        $this->assertNotNull($user->longitude);

        $address = UserAddress::where('user_id', $user->id)->first();
        $this->assertNotNull($address);
        $this->assertSame('القاهرة', $address->governorate);
        $this->assertSame('المعادي', $address->city_or_center);
        $this->assertSame('المعادي القديمة', $address->village_name);
    }
}
