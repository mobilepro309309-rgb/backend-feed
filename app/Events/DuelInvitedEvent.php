<?php

namespace App\Events;

use App\Models\Challenges\DuelRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class DuelInvitedEvent implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public DuelRoom $room;

    public function __construct(DuelRoom $room)
    {
        $this->room = $room;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('private-duel-user.' . $this->room->opponent_id);
    }

    public function broadcastAs(): string
    {
        return 'DuelInvitedEvent';
    }

    public function broadcastWith(): array
    {
        return [
            'room' => $this->room->load(['challenge', 'creator', 'opponent', 'participants.user']),
        ];
    }
}
