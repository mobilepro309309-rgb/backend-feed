<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Chat;
use App\Models\ChatParticipant;
use Illuminate\Support\Facades\DB;

$chat = new Chat(['type' => 'private', 'teacher_id' => 99]);
$chat->save();

foreach ([
    ['chat_id' => $chat->id, 'user_id' => 1],
    ['chat_id' => $chat->id, 'user_id' => 99],
] as $participantData) {
    ChatParticipant::firstOrCreate(
        $participantData,
        ['created_at' => now(), 'updated_at' => now()]
    );
}

echo ChatParticipant::where('chat_id', $chat->id)->count() . PHP_EOL;
