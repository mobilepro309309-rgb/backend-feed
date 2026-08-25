<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'multiple_choice_questions',
            'true_false_questions',
            'daily_challenges',
            'comparison_challenges',
            'find_the_bug_challenges',
            'cloud_capsule_challenges',
            'live_duel_challenges',
            'interactive_videos',
            'video_questions',
        ] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'difficulty')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('difficulty', 20)->default('medium');
                });
            }
        }
    }

    public function down(): void
    {
    }
};
