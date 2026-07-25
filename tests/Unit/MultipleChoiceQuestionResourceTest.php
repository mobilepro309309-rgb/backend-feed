<?php

namespace Tests\Unit;

use App\Http\Resources\Questions\MultipleChoiceQuestionResource;
use App\Models\Questions\MultipleChoiceQuestion;
use Tests\TestCase;

class MultipleChoiceQuestionResourceTest extends TestCase
{
    public function test_it_includes_correct_index_and_correct_answer_value(): void
    {
        $question = new MultipleChoiceQuestion([
            'id' => 1,
            'user_id' => 2,
            'title' => 'Test title',
            'subject' => 'Math',
            'question' => 'What is 2 + 2?',
            'options' => ['3', '4', '5'],
            'correct_index' => 1,
            'badge_text' => 'badge',
            'status' => 'published',
        ]);

        $resource = new MultipleChoiceQuestionResource($question);
        $data = $resource->toArray(request());

        $this->assertSame(1, $data['correct_index']);
        $this->assertSame(1, $data['correct_answer_index']);
        $this->assertSame('4', $data['correct_answer']);
        $this->assertSame('4', $data['correctAnswer']);
    }
}
