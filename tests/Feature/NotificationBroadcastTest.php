<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_broadcast_can_target_users_by_grade_and_role(): void
    {
        User::create([
            'name' => 'Student One',
            'phone' => '01000000001',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'school_grade' => 'الصف الأول',
        ]);

        User::create([
            'name' => 'Student Two',
            'phone' => '01000000002',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'school_grade' => 'الصف الثاني',
        ]);

        User::create([
            'name' => 'Teacher One',
            'phone' => '01000000003',
            'password' => Hash::make('password123'),
            'role' => 'teacher',
            'school_grade' => 'الصف الأول',
        ]);

        $response = $this->postJson('/api/notifications/send', [
            'title' => 'تنبيه',
            'body' => 'رسالة اختبار',
            'target_role' => 'user',
            'target_grade' => 'الصف الأول',
            'type' => 'broadcast',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.count', 1);
        $response->assertJsonPath('data.user_ids.0', 1);
    }
}
