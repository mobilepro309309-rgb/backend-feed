<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Users\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NearbyStudentsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_nearby_students_within_radius(): void
    {
        $currentUser = User::create([
            'name' => 'Current User',
            'phone' => '01011111111',
            'password' => Hash::make('password123'),
            'role' => 'user',
        ]);

        UserAddress::create([
            'user_id' => $currentUser->id,
            'latitude' => 30.0,
            'longitude' => 31.0,
        ]);

        $nearbyUser = User::create([
            'name' => 'Nearby User',
            'phone' => '01022222222',
            'password' => Hash::make('password123'),
            'role' => 'user',
        ]);

        UserAddress::create([
            'user_id' => $nearbyUser->id,
            'latitude' => 30.0005,
            'longitude' => 31.0005,
        ]);

        $farUser = User::create([
            'name' => 'Far User',
            'phone' => '01033333333',
            'password' => Hash::make('password123'),
            'role' => 'user',
        ]);

        UserAddress::create([
            'user_id' => $farUser->id,
            'latitude' => 31.0,
            'longitude' => 32.0,
        ]);

        $response = $this->actingAs($currentUser, 'sanctum')
            ->getJson('/api/nearby-students?latitude=30.0&longitude=31.0&radius=2');

        $response->assertOk();
        $response->assertJsonCount(1, 'students');
        $response->assertJsonPath('students.0.user.id', $nearbyUser->id);
        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('students.0.distance_km', fn($value) => is_numeric($value) && (float) $value <= 2.0);
    }
}
