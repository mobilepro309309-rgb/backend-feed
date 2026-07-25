<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public int $messageId;

    public int $chatId;

    public string $status;

    public function __construct($message)
    {
        $this->messageId = (int) $message->id;
        $this->chatId = (int) $message->chat_id;
        $this->status = (string) ($message->status ?? 'sent');
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('private-chat.' . $this->chatId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'MessageStatusUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'messageId' => $this->messageId,
            'status' => $this->status,
            'chatId' => $this->chatId,
        ];
    }
}
