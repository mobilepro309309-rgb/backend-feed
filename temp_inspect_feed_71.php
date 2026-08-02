<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$feed = App\Models\Feed::find(71);
if (!$feed) {
    echo "feed_not_found";
    exit;
}

$feedable = $feed->feedable()->first();

echo json_encode([
    'feed' => $feed->toArray(),
    'feedable' => $feedable?->toArray(),
]);
