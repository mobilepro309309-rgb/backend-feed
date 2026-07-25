<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$request = new Illuminate\Http\Request();
$request->merge(['feed_type' => 'daily-challenge']);

$controller = new App\Http\Controllers\ChatController();
$method = new ReflectionMethod($controller, 'resolveMessageType');
$method->setAccessible(true);

echo $method->invoke($controller, $request, []);
