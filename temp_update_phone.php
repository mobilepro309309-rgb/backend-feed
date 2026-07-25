<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Illuminate\Support\Facades\DB::connection('mysql')->statement('ALTER TABLE users MODIFY phone VARCHAR(255) NULL');
echo "phone column updated\n";
