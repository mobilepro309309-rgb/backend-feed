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

    return \App\Models\ChatParticipant::where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->exists();
});

Broadcast::channel('private-chat.{chatId}', function ($user, $chatId) {
    if (! $user) {
        return false;
    }

    return \App\Models\ChatParticipant::where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->exists();
});

Broadcast::channel('private-quiz-comments.{quizId}', function ($user) {
    if (! $user) {
        return false;
    }

    return true;
});
