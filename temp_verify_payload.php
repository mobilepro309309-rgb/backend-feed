<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$post = new App\Models\Posts\Post();
$post->forceFill(['id' => 1, 'user_id' => 2, 'subject' => 'Subject', 'content' => 'Content', 'status' => 'published']);
$post->setAttribute('likes', 0);
$post->setAttribute('comments', 0);
$post->setAttribute('shares', 0);

$user = new App\Models\User();
$user->forceFill(['id' => 2, 'name' => 'Author', 'avatar' => 'https://example.com/avatar.png', 'role' => 'user', 'gender' => 'ولد']);
$post->setRelation('user', $user);

$resource = new App\Http\Resources\Posts\PostResource($post);
echo json_encode($resource->toArray(new Illuminate\Http\Request()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
