<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveDuelEligiblePeersTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_peers_returns_only_accepted_friends(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'school_grade' => '10',
        ]);
        $acceptedSender = User::factory()->create([
            'role' => 'user',
            'school_grade' => '10',
        ]);
        $acceptedReceiver = User::factory()->create([
            'role' => 'user',
            'school_grade' => '10',
        ]);
        $pendingFriend = User::factory()->create([
            'role' => 'user',
            'school_grade' => '10',
        ]);
        $sameGradeStranger = User::factory()->create([
            'role' => 'user',
            'school_grade' => '10',
        ]);

        Friendship::create([
            'sender_id' => $acceptedSender->id,
            'receiver_id' => $user->id,
            'status' => 'accepted',
        ]);
        Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $acceptedReceiver->id,
            'status' => 'accepted',
        ]);
        Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $pendingFriend->id,
            'status' => 'pending',
        ]);

        $response = $this->withToken($user->createToken('test-token')->plainTextToken)
            ->getJson('/api/live-duel/eligible-peers');

        $response->assertOk();
        $peerIds = collect($response->json('peers'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$acceptedSender->id, $acceptedReceiver->id],
            $peerIds,
        );
        $this->assertNotContains($pendingFriend->id, $peerIds);
        $this->assertNotContains($sameGradeStranger->id, $peerIds);
    }
}
