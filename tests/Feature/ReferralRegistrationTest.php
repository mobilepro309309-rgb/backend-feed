<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserReferral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_can_register_with_valid_referral_code(): void
    {
        $referrer = User::factory()->create([
            'name' => 'Referrer',
            'phone' => '0500000001',
            'referral_code' => 'ALPHA1',
        ]);

        $response = $this->postJson('/api/register', [
            'name' => 'New User',
            'phone' => '0500000002',
            'password' => 'password123',
            'gender' => 'female',
            'school_grade' => '10',
            'referral_code' => 'ALPHA1',
            'device_identifier' => 'device_ref_001',
            'device_type' => 'android',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'تم إنشاء الحساب بنجاح');

        $newUser = User::where('phone', '0500000002')->firstOrFail();

        $this->assertNotEmpty($newUser->referral_code);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6,8}$/', $newUser->referral_code);
        $this->assertDatabaseHas('user_referrals', [
            'referrer_id' => $referrer->id,
            'referred_id' => $newUser->id,
        ]);
    }

    public function test_me_response_includes_referral_count_for_authenticated_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Main User',
            'phone' => '0500000004',
        ]);

        UserReferral::create([
            'referrer_id' => $user->id,
            'referred_id' => User::factory()->create(['phone' => '0500000005'])->id,
            'points_awarded' => 0,
        ]);

        UserReferral::create([
            'referrer_id' => $user->id,
            'referred_id' => User::factory()->create(['phone' => '0500000006'])->id,
            'points_awarded' => 0,
        ]);

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('user.referred_count', 2);
        $response->assertJsonPath('user.referrals_count', 2);
    }

    public function test_invalid_referral_code_is_rejected(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'New User',
            'phone' => '0500000003',
            'password' => 'password123',
            'referral_code' => 'NOPE99',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['referral_code']);
    }
}
