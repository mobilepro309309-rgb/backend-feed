<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$feeds = App\Models\Feed::query()->with('feedable')->get();
foreach ($feeds as $feed) {
    $feedable = $feed->feedable;
    $details = [
        'feed_id' => $feed->id,
        'feedable_type' => $feed->feedable_type,
        'feedable_id' => $feed->feedable_id,
        'content' => $feedable?->content ?? null,
        'user_id' => $feedable?->user_id ?? null,
        'user_grade' => $feedable?->user?->school_grade ?? null,
    ];
    echo json_encode($details) . PHP_EOL;
}
