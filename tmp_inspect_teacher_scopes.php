<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = DB::table('teacher_scopes')->where('school_grade', '1')->orWhere('school_grade', 1)->get(['id','user_id','school_grade','subject','can_answer','can_create_questions']);

echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
