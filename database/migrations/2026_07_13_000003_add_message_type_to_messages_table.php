<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('messages', 'message_type')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->enum('message_type', ['text', 'post', 'CheatSheetFlipCardQuiz', 'ComparisonCardQuiz', 'DailyChallengeQuiz', 'FindTheBugQuiz', 'LiveDuelCardQuiz', 'MultipleChoiceQuiz', 'TrueFalseQuiz'])
                    ->default('text')
                    ->after('text');
            });

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE messages MODIFY COLUMN message_type ENUM('text', 'post', 'CheatSheetFlipCardQuiz', 'ComparisonCardQuiz', 'DailyChallengeQuiz', 'FindTheBugQuiz', 'LiveDuelCardQuiz', 'MultipleChoiceQuiz', 'TrueFalseQuiz') NOT NULL DEFAULT 'text'");
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages', 'message_type')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('message_type');
            });
        }
    }
};
