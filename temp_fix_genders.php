<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$updates = [
    25 => 'ولد',
    26 => 'بنت',
    30 => 'ولد',
    32 => 'بنت',
    36 => 'ولد',
];

foreach ($updates as $id => $gender) {
    $user = App\Models\User::find($id);
    if ($user) {
        $user->forceFill(['gender' => $gender])->saveQuietly();
        echo $id . ':' . $gender . PHP_EOL;
    }
}
