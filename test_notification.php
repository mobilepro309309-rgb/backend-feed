<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::find(1);
$result = app(App\Services\NotificationService::class)->sendNotification(
    $user,
    'اختبار إشعار حقيقي',
    'تم إرسال هذا الإشعار من Laravel إلى جهازك',
    ['type' => 'new_question', 'question_id' => 999]
);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
