<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};

use App\Http\Controllers\Controller;
use App\Models\{Friendship, User};

class UserSelectionController extends Controller
{
    /**
     * Get all teachers assigned to reply to questions.
     */
    public function getTeachers()
    {
        $teacherFields = $this->getSelectableUserFields();

        $teachers = User::query()
            ->where('role', 'reply_questions_admin')
            ->whereNotNull('name')
            ->select($teacherFields)
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                return $this->formatUserForSelection($user);
            });

        return response()->json([
            'success' => true,
            'data' => $teachers,
        ]);
    }

    /**
     * Get only accepted friends (classmates) for the current user.
     */
    public function getClassmates(Request $request)
    {
        $currentUser = $request->user();
        $currentUserId = $currentUser?->id ?? auth('sanctum')->id() ?? auth()->id();

        if (! $currentUserId) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $friendships = Friendship::query()
            ->where('status', 'accepted')
            ->where(function ($query) use ($currentUserId) {
                $query->where('sender_id', $currentUserId)
                    ->orWhere('receiver_id', $currentUserId);
            })
            ->get();

        $friendIds = $friendships
            ->map(function (Friendship $friendship) use ($currentUserId) {
                return $friendship->sender_id == $currentUserId
                    ? $friendship->receiver_id
                    : $friendship->sender_id;
            })
            ->filter(fn ($id) => (int) $id !== (int) $currentUserId)
            ->unique()
            ->values()
            ->toArray();

        $friendshipMeta = $friendships->mapWithKeys(function (Friendship $friendship) use ($currentUserId) {
            $friendId = $friendship->sender_id == $currentUserId
                ? $friendship->receiver_id
                : $friendship->sender_id;

            return [$friendId => [
                'chat_id' => $friendship->chat_id ?? null,
                'friendship_status' => 'accepted',
                'status' => $friendship->status,
            ]];
        });

        $classmateFields = $this->getSelectableUserFields();

        $classmates = User::query()
            ->whereIn('id', $friendIds)
            ->where('role', 'user')
            ->whereNotNull('name')
            ->select($classmateFields)
            ->orderBy('name')
            ->get()
            ->map(function ($user) use ($friendshipMeta, $currentUserId) {
                $meta = $friendshipMeta[$user->id] ?? [];
                $chatId = $meta['chat_id'] ?? null;

                if (empty($chatId)) {
                    $chatId = DB::table('chat_participants as me')
                        ->join('chat_participants as other', 'me.chat_id', '=', 'other.chat_id')
                        ->where('me.user_id', $currentUserId)
                        ->where('other.user_id', $user->id)
                        ->value('me.chat_id');
                }

                $base = $this->formatUserForSelection($user);

                return array_merge($base, [
                    'chat_id' => $chatId,
                    'chatId' => $chatId,
                    'friendship_status' => $meta['friendship_status'] ?? 'accepted',
                    'status' => $meta['status'] ?? 'accepted',
                ]);
            });

        return response()->json([
            'success' => true,
            'data' => $classmates,
        ]);
    }

    private function getSelectableUserFields(): array
    {
        $fields = ['id', 'name', 'role'];

        if (Schema::hasColumn('users', 'avatar')) {
            $fields[] = 'avatar';
        }

        if (Schema::hasColumn('users', 'school_grade')) {
            $fields[] = 'school_grade';
        }

        if (Schema::hasColumn('users', 'location')) {
            $fields[] = 'location';
        }

        return $fields;
    }

    private function formatUserForSelection(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'label' => $user->name,
            'role' => $user->role,
            'avatar' => $user->avatar ?? null,
            'school_grade' => $user->school_grade ?? null,
            'location' => $user->location ?? null,
            'statusText' => 'متاح الآن',
            'statusColor' => '#22c55e',
        ];
    }
}
