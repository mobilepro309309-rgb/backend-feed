<?php
require 'vendor/autoload.php';

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Create app
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = \Illuminate\Http\Request::capture();
$response = $kernel->handle($request);

use Illuminate\Support\Facades\DB;

// Test database connection
echo "=== Testing Explanation Video URLs ===\n\n";

// Check question_explanations table
$explanations = DB::table('question_explanations')->get();
echo "Total explanation records: " . count($explanations) . "\n";

if (count($explanations) > 0) {
    echo "\nExplanation Records:\n";
    foreach ($explanations as $exp) {
        echo "  - Question Type: {$exp->question_type}, Question ID: {$exp->question_id}, URL: {$exp->video_url}\n";
    }
}

// Check if any questions have explanations
$questionsWithExplanations = DB::table('question_explanations')
    ->groupBy('question_type', 'question_id')
    ->get(['question_type', 'question_id']);

echo "\nQuestions with Explanations:\n";
foreach ($questionsWithExplanations as $q) {
    echo "  - Type: {$q->question_type}, ID: {$q->question_id}\n";
}

// Test each question type
$testQuestionIds = [79, 80, 81, 82, 83, 84, 85, 86];
echo "\n\nTesting Feed API Response:\n";

foreach ($testQuestionIds as $id) {
    try {
        $feed = DB::table('feeds')->where('id', $id)->first();
        if (!$feed) {
            echo "  Feed ID $id: NOT FOUND\n";
            continue;
        }
        
        $explanation = DB::table('question_explanations')
            ->where('question_id', $feed->feedable_id)
            ->first();
        
        echo "  Feed ID $id:\n";
        echo "    - Type: {$feed->feedable_type}\n";
        echo "    - Feedable ID: {$feed->feedable_id}\n";
        echo "    - Has Explanation: " . ($explanation ? 'YES' : 'NO') . "\n";
        if ($explanation) {
            echo "    - Video URL: {$explanation->video_url}\n";
        }
    } catch (\Exception $e) {
        echo "  Feed ID $id: ERROR - {$e->getMessage()}\n";
    }
}

echo "\n=== Test Complete ===\n";
