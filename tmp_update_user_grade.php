<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

DB::table('users')->where('id', 68)->update([
    'gender' => 'ولد',
    'school_grade' => '1st_grade',
]);

$authorIds = DB::table('posts')->pluck('user_id')->unique()->all();
foreach ($authorIds as $authorId) {
    DB::table('users')->where('id', $authorId)->whereNull('gender')->update(['gender' => 'ولد']);
    DB::table('users')->where('id', $authorId)->whereNull('school_grade')->update(['school_grade' => '1st_grade']);
}

$user = DB::table('users')->where('id', 68)->first();
echo 'user68 gender=' . $user->gender . PHP_EOL;
echo 'user68 school_grade=' . $user->school_grade . PHP_EOL;

$viewer = App\Models\User::find(68);
if ($viewer) {
    auth()->setUser($viewer);
    $feed = app(App\Services\FeedService::class)->getPaginatedFeed(10);
    $items = $feed->getCollection();
    echo 'feed_count=' . $items->count() . PHP_EOL;
    foreach ($items as $item) {
        $author = $item->feedable?->user ?? null;
        echo 'post=' . ($item->feedable->content ?? '') . ' author_grade=' . ($author->school_grade ?? 'null') . PHP_EOL;
    }
}
