<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherFriendsTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_friends_returns_only_accepted_teacher_friendships(): void
    {
        $user = User::factory()->create();
        $teacherFromSenderSide = User::factory()->create(['role' => 'teacher']);
        $teacherFromReceiverSide = User::factory()->create(['role' => 'teacher']);
        $studentFriend = User::factory()->create();
        $pendingTeacher = User::factory()->create(['role' => 'teacher']);

        Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $teacherFromSenderSide->id,
            'status' => 'accepted',
            'teacher' => 1,
        ]);
        Friendship::create([
            'sender_id' => $teacherFromReceiverSide->id,
            'receiver_id' => $user->id,
            'status' => 'accepted',
            'teacher' => 1,
        ]);
        Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $studentFriend->id,
            'status' => 'accepted',
            'teacher' => 0,
        ]);
        Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $pendingTeacher->id,
            'status' => 'pending',
            'teacher' => 1,
        ]);

        $response = $this->withToken($user->createToken('test-token')->plainTextToken)
            ->getJson('/api/friends?type=teachers');

        $response->assertOk();
        $teacherIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$teacherFromSenderSide->id, $teacherFromReceiverSide->id],
            $teacherIds,
        );
        $this->assertNotContains($studentFriend->id, $teacherIds);
        $this->assertNotContains($pendingTeacher->id, $teacherIds);
    }

    public function test_colleagues_excludes_accepted_teacher_friendships(): void
    {
        $user = User::factory()->create();
        $studentFriend = User::factory()->create();
        $teacherFriend = User::factory()->create(['role' => 'teacher']);

        Friendship::create([
            'sender_id' => $user->id,
            'receiver_id' => $studentFriend->id,
            'status' => 'accepted',
            'teacher' => 0,
        ]);
        Friendship::create([
            'sender_id' => $teacherFriend->id,
            'receiver_id' => $user->id,
            'status' => 'accepted',
            'teacher' => 1,
        ]);

        $response = $this->withToken($user->createToken('test-token')->plainTextToken)
            ->getJson('/api/friends?type=colleagues');

        $response->assertOk();
        $colleagueIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($studentFriend->id, $colleagueIds);
        $this->assertNotContains($teacherFriend->id, $colleagueIds);
    }
}
