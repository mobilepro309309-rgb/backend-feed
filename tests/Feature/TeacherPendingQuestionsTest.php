<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatParticipant;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherPendingQuestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_fetch_pending_student_questions(): void
    {
        $teacher = User::factory()->create([
            'role' => 'reply_questions_admin',
            'name' => 'Teacher One',
            'school_grade' => '3',
        ]);

        $student = User::factory()->create([
            'role' => 'user',
            'name' => 'Student One',
            'school_grade' => '3',
        ]);

        $chat = Chat::create([
            'type' => 'private',
            'teacher_id' => $teacher->id,
        ]);

        ChatParticipant::insert([
            ['chat_id' => $chat->id, 'user_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now()],
            ['chat_id' => $chat->id, 'user_id' => $student->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'sender_id' => $student->id,
            'text' => 'سؤال جديد من الطالب',
            'message_type' => 'text',
            'status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($teacher, 'sanctum')->getJson('/api/teacher/pending-questions');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment([
            'chat_id' => $chat->id,
            'student_id' => $student->id,
            'student_name' => $student->name,
            'school_grade' => $student->school_grade,
            'last_message' => 'سؤال جديد من الطالب',
            'message_type' => 'text',
            'sent_by_student' => true,
        ]);
    }

    public function test_teacher_endpoint_returns_friendship_record_with_chat_id_for_student_interactions(): void
    {
        $teacher = User::factory()->create([
            'role' => 'reply_questions_admin',
            'name' => 'Teacher Two',
            'school_grade' => '3',
        ]);

        $student = User::factory()->create([
            'role' => 'user',
            'name' => 'Student Two',
            'school_grade' => '3',
        ]);

        $chat = Chat::create([
            'type' => 'private',
            'teacher_id' => $teacher->id,
        ]);

        ChatParticipant::insert([
            ['chat_id' => $chat->id, 'user_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now()],
            ['chat_id' => $chat->id, 'user_id' => $student->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $friendship = Friendship::create([
            'sender_id' => $student->id,
            'receiver_id' => $teacher->id,
            'status' => 'accepted',
            'chat_id' => $chat->id,
            'teacher' => 1,
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'sender_id' => $student->id,
            'text' => 'محادثة من خلال friendship',
            'message_type' => 'text',
            'status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($teacher, 'sanctum')->getJson('/api/teacher/pending-questions');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.chat_id', $chat->id);
        $response->assertJsonPath('data.0.student.id', $student->id);
        $response->assertJsonPath('data.0.student.name', $student->name);
        $response->assertJsonPath('data.0.friendship.id', $friendship->id);
        $response->assertJsonPath('data.0.friendship.teacher', 1);
    }
}
