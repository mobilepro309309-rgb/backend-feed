<?php

namespace Tests\Feature;

use App\Models\TeacherScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherSelectionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_teachers_for_post_uses_teacher_scopes_for_the_students_grade_and_subject(): void
    {
        $student = User::factory()->create([
            'role' => 'user',
            'school_grade' => 'أولي',
        ]);

        $matchingTeacher = User::factory()->create([
            'role' => 'teacher',
            'name' => 'أحمد المعلم',
        ]);

        $nonMatchingTeacher = User::factory()->create([
            'role' => 'teacher',
            'name' => 'سلمان المعلم',
        ]);

        TeacherScope::create([
            'user_id' => $matchingTeacher->id,
            'school_grade' => '1',
            'subject' => 'رياضيات',
            'can_answer' => true,
        ]);

        TeacherScope::create([
            'user_id' => $nonMatchingTeacher->id,
            'school_grade' => '2',
            'subject' => 'رياضيات',
            'can_answer' => true,
        ]);

        TeacherScope::create([
            'user_id' => $matchingTeacher->id,
            'school_grade' => '1',
            'subject' => 'علوم',
            'can_answer' => true,
        ]);

        $response = $this->actingAs($student)->getJson('/api/teachers/available-for-post?subject=رياضيات');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matchingTeacher->id);
        $response->assertJsonPath('data.0.name', 'أحمد المعلم');
    }
}
