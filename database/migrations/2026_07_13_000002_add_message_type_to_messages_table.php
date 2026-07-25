<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('message_type', ['text', 'post', 'CheatSheetFlipCardQuiz', 'ComparisonCardQuiz', 'DailyChallengeQuiz', 'FindTheBugQuiz', 'LiveDuelCardQuiz', 'MultipleChoiceQuiz', 'TrueFalseQuiz'])
                ->default('text')
                ->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'message_type')) {
                $table->dropColumn('message_type');
            }
        });
    }
};
