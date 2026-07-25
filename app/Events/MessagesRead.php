<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class MessagesRead implements ShouldBroadcast
{
    public int $chatId;

    public int $readerId;

    public int $updatedCount;

    public string $readAt;

    public function __construct(int $chatId, int $readerId, int $updatedCount)
    {
        $this->chatId = $chatId;
        $this->readerId = $readerId;
        $this->updatedCount = $updatedCount;
        $this->readAt = now()->toISOString();
    }

    public function broadcastOn(): array
    {
        $participantIds = \App\Models\ChatParticipant::where('chat_id', $this->chatId)
            ->where('user_id', '!=', $this->readerId)
            ->pluck('user_id');

        return $participantIds->map(function (int $participantId): PrivateChannel {
            return new PrivateChannel('private-chat.' . $participantId);
        })->all();
    }

    public function broadcastWith(): array
    {
        return [
            'chatId' => $this->chatId,
            'readerId' => $this->readerId,
            'updatedCount' => $this->updatedCount,
            'readAt' => $this->readAt,
            'type' => 'messages_read',
        ];
    }
}
