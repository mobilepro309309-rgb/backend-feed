<?php

namespace App\Events;

use App\Models\Challenges\DuelRoom;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DuelJoinedEvent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public $room;

    public function __construct(DuelRoom $room)
    {
        // Fix: Load the challenge model only, because `questions` is a JSON attribute.
        $this->room = $room->load(['challenge', 'creator', 'opponent']);
    }

    public function broadcastOn()
    {
        return new PrivateChannel('duel.' . $this->room->id);
    }

    public function broadcastAs()
    {
        return 'DuelJoinedEvent';
    }

    public function broadcastWith()
    {
        return [
            'room_id' => $this->room->id,
            'status' => 'active',
            'questions' => $this->room->challenge->questions ?? [],
            'started_at' => optional($this->room->started_at)->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
