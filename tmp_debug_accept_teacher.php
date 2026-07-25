<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\FriendshipController;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$user = User::query()->first();
$teacher = User::query()->where('id', '!=', $user->id)->first();

if (! $user || ! $teacher) {
    echo "No suitable users\n";
    exit(1);
}

$request = new Request(['teacher_id' => $teacher->id]);
$request->setUserResolver(function () use ($user) {
    return $user;
});

$response = (new FriendshipController())->acceptTeacher($request);

echo "STATUS: " . $response->getStatusCode() . PHP_EOL;
echo "BODY: " . $response->getContent() . PHP_EOL;

echo "FRIENDSHIP_COUNT: " . Friendship::query()->count() . PHP_EOL;
$friendship = Friendship::query()->where(function ($query) use ($user, $teacher) {
    $query->where('sender_id', $user->id)->where('receiver_id', $teacher->id);
})->orWhere(function ($query) use ($user, $teacher) {
    $query->where('sender_id', $teacher->id)->where('receiver_id', $user->id);
})->latest()->first();

if ($friendship) {
    echo "FRIENDSHIP_ID: " . $friendship->id . PHP_EOL;
    echo "FRIENDSHIP_STATUS: " . $friendship->status . PHP_EOL;
    echo "FRIENDSHIP_CHAT_ID: " . ($friendship->chat_id ?? 'null') . PHP_EOL;
}

echo "CHATS_COUNT: " . DB::table('chats')->count() . PHP_EOL;
echo "PARTICIPANTS_COUNT: " . DB::table('chat_participants')->count() . PHP_EOL;
if ($friendship && $friendship->chat_id) {
    echo "CHAT_PARTICIPANTS_FOR_CHAT: " . DB::table('chat_participants')->where('chat_id', $friendship->chat_id)->count() . PHP_EOL;
    echo "PARTICIPANT_USERS: " . implode(', ', DB::table('chat_participants')->where('chat_id', $friendship->chat_id)->pluck('user_id')->all()) . PHP_EOL;
}
