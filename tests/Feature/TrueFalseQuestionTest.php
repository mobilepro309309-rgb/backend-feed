<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrueFalseQuestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_true_false_question(): void
    {
        $user = User::factory()->create([
            'role' => 'question_post_admin',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/true-false-questions', [
                'title' => 'سؤال صح أم خطأ',
                'subject' => 'علوم',
                'prompt' => 'الأرض تدور حول الشمس.',
                'correct_answer' => true,
                'explanation' => 'شرح مختصر',
                'badge_text' => 'صح أم خطأ؟',
                'status' => 'draft',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('true_false_questions', [
            'title' => 'سؤال صح أم خطأ',
            'subject' => 'علوم',
            'user_id' => $user->id,
        ]);
    }
}
