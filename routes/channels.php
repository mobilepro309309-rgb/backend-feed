<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.status', function ($user) {
    if (! $user) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});

Broadcast::channel('presence-global', function ($user) {
    if (! $user) {
        return false;
    }

    $profile = $user->profile()->first();

    return [
        'id' => $user->id,
        'name' => $user->name,
        'avatar' => $profile?->avatar_url ?? $profile?->avatar ?? $profile?->profile_image ?? $profile?->imageUrl ?? null,
        'avatar_url' => $profile?->avatar_url ?? $profile?->avatar ?? $profile?->profile_image ?? $profile?->imageUrl ?? null,
    ];
});

Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    if (! $user) {
        return false;
    }

    // Allow access when the user is a chat participant OR when the user is the assigned teacher for the chat.
    $isParticipant = \App\Models\ChatParticipant::where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->exists();

    if ($isParticipant) {
        return true;
    }

    // Also allow access when the chat's teacher_id matches the user id (teacher-driven chats)
    $isTeacherForChat = \App\Models\Chat::where('id', $chatId)
        ->where('teacher_id', $user->id)
        ->exists();

    return $isTeacherForChat;
});

Broadcast::channel('presence-chat.{chatId}', function ($user, $chatId) {
    if (! $user) {
        return false;
    }

    $isParticipant = \App\Models\ChatParticipant::where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->exists();

    if (! $isParticipant) {
        $isTeacherForChat = \App\Models\Chat::where('id', $chatId)
            ->where('teacher_id', $user->id)
            ->exists();

        if (! $isTeacherForChat) {
            return false;
        }
    }

    $profile = $user->profile()->first();

    return [
        'id' => $user->id,
        'name' => $user->name,
        'avatar' => $profile?->avatar_url ?? $profile?->avatar ?? $profile?->profile_image ?? $profile?->imageUrl ?? null,
        'avatar_url' => $profile?->avatar_url ?? $profile?->avatar ?? $profile?->profile_image ?? $profile?->imageUrl ?? null,
    ];
});

Broadcast::channel('private-chat.{chatId}', function ($user, $chatId) {
    if (! $user) {
        return false;
    }

    // Mirror the same authorization as the public chat channel: participants or the chat's teacher
    $isParticipant = \App\Models\ChatParticipant::where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->exists();

    if ($isParticipant) return true;

    $isTeacherForChat = \App\Models\Chat::where('id', $chatId)
        ->where('teacher_id', $user->id)
        ->exists();

    return $isTeacherForChat;
});

Broadcast::channel('private-user.{userId}', function ($user, $userId) {
    if (! $user) {
        return false;
    }

    return (int) $user->id === (int) $userId;
});

Broadcast::channel('private-duel-user.{userId}', function ($user, $userId) {
    if (! $user) {
        return false;
    }

    return (int) $user->id === (int) $userId;
});

Broadcast::channel('private-duel.{roomId}', function ($user, $roomId) {
    if (! $user) {
        return false;
    }

    $room = \App\Models\Challenges\DuelRoom::find($roomId);
    if (! $room) {
        return false;
    }

    return (int) $room->creator_id === (int) $user->id
        || (int) $room->opponent_id === (int) $user->id;
});

// Authorize the `duel.{roomId}` private channel (used by events broadcasting to PrivateChannel('duel.{id}'))
Broadcast::channel('duel.{roomId}', function ($user, $roomId) {
    if (! $user) {
        return false;
    }

    $room = \App\Models\Challenges\DuelRoom::find($roomId);
    if (! $room) {
        return false;
    }

    return (int) $room->creator_id === (int) $user->id
        || (int) $room->opponent_id === (int) $user->id;
});

Broadcast::channel('private-quiz-comments.{quizId}', function ($user) {
    if (! $user) {
        return false;
    }

    return true;
});
