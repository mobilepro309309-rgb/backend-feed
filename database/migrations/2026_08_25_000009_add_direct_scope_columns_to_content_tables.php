<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'multiple_choice_questions',
        'true_false_questions',
        'cloud_capsule_challenges',
        'comparison_challenges',
        'daily_challenges',
        'find_the_bug_challenges',
        'live_duel_challenges',
        'interactive_videos',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'stage_id')) {
                    $table->unsignedBigInteger('stage_id')->nullable()->after('subject_id');
                    $table->index('stage_id');
                }

                if (!Schema::hasColumn($tableName, 'grade_id')) {
                    $table->unsignedBigInteger('grade_id')->nullable()->after('stage_id');
                    $table->index('grade_id');
                }

                if (!Schema::hasColumn($tableName, 'track_id')) {
                    $table->unsignedBigInteger('track_id')->nullable()->after('grade_id');
                    $table->index('track_id');
                }

                $table->index(['stage_id', 'grade_id', 'track_id']);
            });
        }
    }

    public function down(): void
    {
    }
};
