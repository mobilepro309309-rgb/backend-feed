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

Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    if (! $user) {
        return false;
    }

    // Allow access when the user is a chat participant OR when the user is the assigned teacher for the chat.
    $isParticipant = \App\Models\ChatParticipant::where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->exists();

    if ($isParticipant) return true;

    // Also allow access when the chat's teacher_id matches the user id (teacher-driven chats)
    $isTeacherForChat = \App\Models\Chat::where('id', $chatId)
        ->where('teacher_id', $user->id)
        ->exists();

    return $isTeacherForChat;
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

Broadcast::channel('private-quiz-comments.{quizId}', function ($user) {
    if (! $user) {
        return false;
    }

    return true;
});
