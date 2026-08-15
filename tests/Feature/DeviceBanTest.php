<?php

namespace Tests\Feature;

use App\Models\BannedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceBanTest extends TestCase
{
    use RefreshDatabase;

    public function test_banned_device_cannot_login(): void
    {
        $user = User::factory()->create([
            'phone' => '01234567890',
            'password' => bcrypt('password123'),
        ]);

        $deviceIdentifier = 'android_device_12345';

        BannedDevice::create([
            'device_identifier' => $deviceIdentifier,
            'reason' => 'Test ban',
            'banned_by' => 1,
        ]);

        $response = $this->postJson('/api/login', [
            'phone' => '01234567890',
            'password' => 'password123',
            'device_identifier' => $deviceIdentifier,
            'device_type' => 'android',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('status', 'device_banned');
        $response->assertJsonPath('message', 'عذراً، هذا الجهاز محظور من استخدام المنصة');
    }

    public function test_banned_device_cannot_register(): void
    {
        $deviceIdentifier = 'ios_device_67890';

        BannedDevice::create([
            'device_identifier' => $deviceIdentifier,
            'reason' => 'Test ban',
            'banned_by' => 1,
        ]);

        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'phone' => '01234567890',
            'password' => 'password123',
            'device_identifier' => $deviceIdentifier,
            'device_type' => 'ios',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('status', 'device_banned');
    }

    public function test_normal_device_can_login(): void
    {
        $user = User::factory()->create([
            'phone' => '01234567890',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'phone' => '01234567890',
            'password' => 'password123',
            'device_identifier' => 'normal_device_123',
            'device_type' => 'android',
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('token'));
    }

    public function test_admin_can_ban_device(): void
    {
        $admin = User::factory()->create(['role' => 'main-admin']);
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/admin/devices/ban', [
            'device_identifier' => 'test_device_123',
            'reason' => 'Suspicious activity detected',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('banned_devices', [
            'device_identifier' => 'test_device_123',
            'reason' => 'Suspicious activity detected',
            'banned_by' => $admin->id,
        ]);
    }

    public function test_admin_can_unban_device(): void
    {
        $admin = User::factory()->create(['role' => 'main-admin']);

        $ban = BannedDevice::create([
            'device_identifier' => 'test_device_123',
            'reason' => 'Test ban',
            'banned_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->deleteJson("/api/admin/devices/unban/{$ban->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('banned_devices', [
            'id' => $ban->id,
        ]);
    }

    public function test_admin_can_list_banned_devices(): void
    {
        $admin = User::factory()->create(['role' => 'main-admin']);

        BannedDevice::create([
            'device_identifier' => 'device_1',
            'reason' => 'Ban 1',
            'banned_by' => $admin->id,
        ]);

        BannedDevice::create([
            'device_identifier' => 'device_2',
            'reason' => 'Ban 2',
            'banned_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/devices/banned');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $response->assertJsonPath('pagination.total', 2);
    }

    public function test_banning_by_user_id_bans_all_devices_for_that_user(): void
    {
        $admin = User::factory()->create(['role' => 'main-admin']);
        $user = User::factory()->create();

        $firstDevice = 'user_device_001';
        $secondDevice = 'user_device_002';

        $user->devices()->create(['device_identifier' => $firstDevice, 'device_type' => 'android']);
        $user->devices()->create(['device_identifier' => $secondDevice, 'device_type' => 'ios']);

        $response = $this->actingAs($admin)->postJson('/api/admin/devices/ban', [
            'user_id' => $user->id,
            'reason' => 'User account flagged',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('banned_devices', [
            'device_identifier' => $firstDevice,
            'reason' => 'User account flagged',
            'banned_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('banned_devices', [
            'device_identifier' => $secondDevice,
            'reason' => 'User account flagged',
            'banned_by' => $admin->id,
        ]);
        $this->assertCount(2, BannedDevice::whereIn('device_identifier', [$firstDevice, $secondDevice])->get());
    }
}
