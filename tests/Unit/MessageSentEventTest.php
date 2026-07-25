<?php

namespace Tests\Unit;

use App\Events\MessageSent;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class MessageSentEventTest extends TestCase
{
    public function test_it_broadcasts_with_a_stable_event_name_and_private_channel(): void
    {
        $message = new \stdClass();
        $message->chat_id = 7;
        $message->sender_id = 2;
        $message->text = 'hello';
        $message->created_at = '2024-01-01 00:00:00';
        $message->updated_at = '2024-01-01 00:00:00';

        $event = new MessageSent($message);

        $this->assertSame('MessageSent', $event->broadcastAs());

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-chat.7', $channels[0]->name);
    }
}
