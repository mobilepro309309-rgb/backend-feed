<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$users = DB::table('users')->select('id', 'name', 'gender', 'school_grade')->orderBy('id')->limit(30)->get();
foreach ($users as $user) {
    echo $user->id . ' | ' . ($user->name ?? '') . ' | gender=' . ($user->gender ?? 'NULL') . ' | school_grade=' . ($user->school_grade ?? 'NULL') . PHP_EOL;
}

$posts = DB::table('posts')->select('id', 'user_id', 'content')->orderBy('id')->limit(10)->get();
foreach ($posts as $post) {
    $author = DB::table('users')->where('id', $post->user_id)->select('id', 'gender', 'school_grade')->first();
    echo 'POST ' . $post->id . ' by user ' . $post->user_id . ' => author_gender=' . ($author->gender ?? 'NULL') . ' author_school_grade=' . ($author->school_grade ?? 'NULL') . PHP_EOL;
}
