<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewNotificationEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Notification $notification,
        public int $userId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('private-user.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'NewNotificationEvent';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => [
                'id' => $this->notification->id,
                'title' => $this->notification->title,
                'body' => $this->notification->body,
                'type' => $this->notification->type,
                'data' => $this->notification->data ?? [],
                'created_at' => $this->notification->created_at?->toISOString(),
            ],
            'user_id' => $this->userId,
        ];
    }
}
