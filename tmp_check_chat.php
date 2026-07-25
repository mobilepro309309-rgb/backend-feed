<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$chat = App\Models\Chat::find(14);
if (! $chat) {
    echo "CHAT_NOT_FOUND\n";
    exit;
}

echo "CHAT_ID=$chat->id\n";
foreach ($chat->participants as $participant) {
    echo "USER_ID={$participant->user_id}\n";
}
