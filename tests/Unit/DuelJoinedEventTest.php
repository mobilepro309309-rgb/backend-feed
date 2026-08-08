<?php

namespace Tests\Unit;

use App\Events\DuelJoinedEvent;
use App\Models\Challenges\DuelRoom;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuelJoinedEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_broadcasts_immediately_to_the_duel_private_channel(): void
    {
        $challenge = \App\Models\Challenges\Challenge::factory()->create();
        $creator = \App\Models\User::factory()->create();
        $opponent = \App\Models\User::factory()->create();

        $room = DuelRoom::factory()->create([
            'challenge_id' => $challenge->id,
            'creator_id' => $creator->id,
            'opponent_id' => $opponent->id,
            'status' => 'active',
        ]);

        $event = new DuelJoinedEvent($room);

        $this->assertSame('DuelJoinedEvent', $event->broadcastAs());

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('duel.' . $room->id, $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertSame('active', $payload['status']);
        $this->assertSame($room->id, $payload['room_id']);
        $this->assertArrayHasKey('started_at', $payload);
        $this->assertIsArray($payload['questions']);
    }
}
