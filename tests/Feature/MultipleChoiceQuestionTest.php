<?php

namespace Tests\Feature;

use App\Models\NotificationUser;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultipleChoiceQuestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_multiple_choice_question(): void
    {
        $user = User::factory()->create([
            'role' => 'question_post_admin',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/multiple-choice-questions', [
                'title' => 'سؤال اختبار',
                'subject' => 'علوم',
                'school_grade' => '1',
                'unit_number' => 3,
                'question' => 'ما هو الكوكب الأحمر؟',
                'options' => ['المريخ', 'الزهرة', 'الأرض', 'الزحل'],
                'correct_index' => 0,
                'badge_text' => 'اختيار من متعدد',
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('multiple_choice_questions', [
            'title' => 'سؤال اختبار',
            'subject' => 'علوم',
            'school_grade' => '1',
            'unit_number' => 3,
            'user_id' => $user->id,
        ]);
    }

    public function test_question_notification_is_created_for_any_user_with_a_registered_device(): void
    {
        $admin = User::factory()->create([
            'role' => 'question_post_admin',
        ]);
        $recipient = User::factory()->create([
            'role' => 'teacher',
        ]);

        UserDevice::create([
            'user_id' => $recipient->id,
            'fcm_token' => 'test-token-for-teacher',
            'device_type' => 'android',
        ]);

        $token = $admin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/multiple-choice-questions', [
                'title' => 'سؤال إشعار',
                'subject' => 'رياضيات',
                'question' => 'ما هي النتيجة؟',
                'options' => ['1', '2', '3', '4'],
                'correct_index' => 0,
                'badge_text' => 'اختيار من متعدد',
                'status' => 'draft',
            ]);

        $response->assertCreated();

        $notification = $recipient->notifications()->latest()->first();

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notification_user', [
            'user_id' => $recipient->id,
        ]);
    }
}
