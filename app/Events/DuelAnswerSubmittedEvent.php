<?php

namespace App\Events;

use App\Models\Challenges\DuelParticipant;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DuelAnswerSubmittedEvent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public DuelParticipant $participant)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('duel.' . $this->participant->room_id);
    }

    public function broadcastAs(): string
    {
        return 'DuelAnswerSubmittedEvent';
    }

    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->participant->room_id,
            'participant_user_id' => $this->participant->user_id,
            'correct_count' => (int) $this->participant->score,
            'wrong_count' => max(0, (int) $this->participant->answered_count - (int) $this->participant->score),
            'answered_count' => (int) $this->participant->answered_count,
        ];
    }
}
