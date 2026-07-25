<?php

namespace Tests\Unit;

use App\Http\Controllers\ChatController;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ChatMessageTextResolutionTest extends TestCase
{
    public function test_shared_content_id_is_used_as_message_text_for_non_text_messages(): void
    {
        $controller = new ChatController();
        $request = new Request();
        $request->merge([
            'text' => 'hello',
            'shared_content_id' => '42',
        ]);

        $method = new \ReflectionMethod($controller, 'resolveMessageText');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $request, ['message_type' => 'MultipleChoiceQuiz'], 'MultipleChoiceQuiz');

        $this->assertSame('42', $result);
    }
}
