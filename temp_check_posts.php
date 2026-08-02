<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::query()->where('school_grade', '1')->first();
echo 'user=' . ($user?->id ?? 'null') . ' grade=' . ($user?->school_grade ?? 'null') . PHP_EOL;

$posts = App\Models\Posts\Post::query()->with('user')->get();
foreach ($posts as $post) {
    echo $post->id . ':' . ($post->user?->school_grade ?? 'null') . PHP_EOL;
}
