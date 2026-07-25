<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::find(1);
if (! $user) {
    echo "NO_USER\n";
    exit;
}

$items = App\Models\NotificationUser::where('user_id', $user->id)->with('notification')->orderByDesc('created_at')->get();

echo json_encode([
    'user_id' => $user->id,
    'count' => $items->count(),
    'items' => $items->map(function ($i) {
        return [
            'id' => $i->id,
            'notification_id' => $i->notification_id,
            'title' => $i->notification?->title,
            'body' => $i->notification?->body,
            'created_at' => $i->created_at,
        ];
    })->toArray(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
