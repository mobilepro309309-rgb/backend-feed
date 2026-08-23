<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::query()->firstOrFail();
$key = (string) Illuminate\Support\Str::uuid();
$service = app(App\Services\IdempotencyService::class);
$first = $service->execute($user->id, $key, 'tmp.post.create', function () use ($user) {
    return App\Models\Posts\Post::create(['user_id' => $user->id, 'content' => 'store verification', 'subject' => 'test', 'status' => 'pending'])->fresh()->load('user')->toArray();
}, App\Models\Posts\Post::class);
$second = $service->execute($user->id, $key, 'tmp.post.create', function () { return ['id' => 999999]; }, App\Models\Posts\Post::class);
echo (($first['value']['id'] ?? 0) === ($second['value']['id'] ?? -1) ? 'IDEMPOTENCY_OK' : 'IDEMPOTENCY_FAILED');
App\Models\IdempotencyRecord::query()->where('client_action_id', $key)->delete();
App\Models\Posts\Post::query()->whereKey($first['value']['id'])->delete();
