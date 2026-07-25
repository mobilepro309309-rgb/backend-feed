<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

use App\Models\User;
use Tests\TestCase;

class AuthPhoneEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_is_encrypted_when_registered_and_can_login(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'phone' => '01012345678',
            'password' => 'password123',
        ]);

        $response->assertStatus(201);

        $user = User::query()->latest('id')->first();
        $this->assertNotNull($user);
        $this->assertSame('01012345678', $user->phone);
        $this->assertNotSame('01012345678', $user->getRawOriginal('phone'));

        $loginResponse = $this->postJson('/api/login', [
            'phone' => '01012345678',
            'password' => 'password123',
        ]);

        $loginResponse->assertStatus(200);
    }

    public function test_duplicate_phone_registration_is_rejected(): void
    {
        $firstResponse = $this->postJson('/api/register', [
            'name' => 'First User',
            'phone' => '01000000000',
            'password' => 'password123',
        ]);

        $firstResponse->assertStatus(201);

        $secondResponse = $this->postJson('/api/register', [
            'name' => 'Second User',
            'phone' => '01000000000',
            'password' => 'password123',
        ]);

        $secondResponse->assertStatus(422);
        $secondResponse->assertJsonValidationErrors(['phone']);
    }
}
