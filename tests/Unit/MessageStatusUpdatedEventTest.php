<?php

namespace Tests\Unit;

use App\Events\MessageStatusUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class MessageStatusUpdatedEventTest extends TestCase
{
    public function test_it_broadcasts_status_updates_to_the_chat_channel(): void
    {
        $message = new \stdClass();
        $message->id = 42;
        $message->chat_id = 7;
        $message->status = 'delivered';

        $event = new MessageStatusUpdated($message);

        $this->assertEquals([new PrivateChannel('private-chat.7')], $event->broadcastOn());
        $this->assertSame('MessageStatusUpdated', $event->broadcastAs());
        $this->assertSame([
            'messageId' => 42,
            'status' => 'delivered',
            'chatId' => 7,
        ], $event->broadcastWith());
    }
}
