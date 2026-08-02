<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InteractiveVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_interactive_video_question_with_file_only_question_text(): void
    {
        $user = User::factory()->create([
            'role' => 'question_post_admin',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/interactive-videos', [
                'title' => 'فيديو تفاعلي مع سؤال بدون نص',
                'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'subject' => 'رياضيات',
                'number_of_questions' => 1,
                'questions' => [
                    [
                        'question_text' => '',
                        'choice_1' => 'خيار 1',
                        'choice_2' => 'خيار 2',
                        'choice_3' => 'خيار 3',
                        'choice_4' => 'خيار 4',
                        'correct_choice' => 1,
                        'stop_minute' => 0,
                        'stop_second' => 15,
                        'file_url' => 'https://cdn.example.com/question-attachment.pdf',
                    ],
                ],
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('interactive_videos', [
            'title' => 'فيديو تفاعلي مع سؤال بدون نص',
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'subject' => 'رياضيات',
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('video_questions', [
            'file_url' => 'https://cdn.example.com/question-attachment.pdf',
        ]);
    }
}
