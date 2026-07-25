<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$sender = User::factory()->create();
$receiver = User::factory()->create();
Auth::login($sender);

$request = new Request();
$request->merge([
    'receiver_id' => $receiver->id,
    'text' => 'shared',
    'feed_type' => 'daily-challenge',
]);

$controller = new ChatController();
$response = $controller->createSharedMessage($request);
$message = $response->getData()->message;

echo 'saved_type=' . $message->message_type . PHP_EOL;
