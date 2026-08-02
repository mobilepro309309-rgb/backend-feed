<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Feed;
use App\Models\Posts\Post;
use App\Models\User;
use App\Services\FeedService;
use Illuminate\Support\Facades\Auth;

$viewer = User::create([
    'name' => 'GradeViewer'.time(),
    'phone' => '970'.rand(100000000,999999999),
    'email' => 'gradeviewer'.time().'@example.com',
    'password' => bcrypt('password'),
    'role' => 'user',
    'school_grade' => '1',
]);

$matchingAuthor = User::create([
    'name' => 'MatchingAuthor'.time(),
    'phone' => '971'.rand(100000000,999999999),
    'email' => 'matching'.time().'@example.com',
    'password' => bcrypt('password'),
    'role' => 'user',
    'school_grade' => '1',
]);

$differentAuthor = User::create([
    'name' => 'DifferentAuthor'.time(),
    'phone' => '972'.rand(100000000,999999999),
    'email' => 'different'.time().'@example.com',
    'password' => bcrypt('password'),
    'role' => 'user',
    'school_grade' => '2',
]);

$matchingPost = Post::create([
    'user_id' => $matchingAuthor->id,
    'subject' => 'same grade',
    'content' => 'same grade post',
    'status' => 'published',
]);
Feed::create([
    'feedable_type' => Post::class,
    'feedable_id' => $matchingPost->id,
    'status' => 'active',
]);

$differentPost = Post::create([
    'user_id' => $differentAuthor->id,
    'subject' => 'different grade',
    'content' => 'different grade post',
    'status' => 'published',
]);
Feed::create([
    'feedable_type' => Post::class,
    'feedable_id' => $differentPost->id,
    'status' => 'active',
]);

Auth::login($viewer);

$service = app(FeedService::class);
$feed = $service->getPaginatedFeed(10);

$contents = $feed->getCollection()->pluck('feedable.content')->all();

echo 'viewer_grade=' . $viewer->school_grade . PHP_EOL;
echo 'results=' . json_encode($contents) . PHP_EOL;
