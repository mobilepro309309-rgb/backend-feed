<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;
    public int $chatId;

    public int $userId;

    public bool $isTyping;

    public string $timestamp;

    public function __construct(int $chatId, int $userId, bool $isTyping)
    {
        $this->chatId = $chatId;
        $this->userId = $userId;
        $this->isTyping = $isTyping;
        $this->timestamp = now()->toISOString();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('private-chat.' . $this->chatId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'UserTyping';
    }

    public function broadcastWith(): array
    {
        return [
            'chatId' => $this->chatId,
            'userId' => $this->userId,
            'isTyping' => $this->isTyping,
            'timestamp' => $this->timestamp,
            'type' => 'user_typing',
        ];
    }
}
