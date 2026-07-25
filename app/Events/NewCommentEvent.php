<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class NewCommentEvent implements ShouldBroadcast
{
    public function __construct(
        public int $channelId,
        public array $comment
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('private-quiz-comments.' . $this->channelId)];
    }

    public function broadcastAs(): string
    {
        return 'NewCommentEvent';
    }

    public function broadcastWith(): array
    {
        return [
            'comment' => $this->comment,
            'quiz_id' => $this->channelId,
        ];
    }
}
