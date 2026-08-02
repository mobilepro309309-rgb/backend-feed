<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

foreach ([25, 26, 30, 32, 36] as $id) {
    $user = App\Models\User::find($id);
    echo $id . ':' . ($user && $user->gender !== null ? $user->gender : 'NULL') . PHP_EOL;
}
