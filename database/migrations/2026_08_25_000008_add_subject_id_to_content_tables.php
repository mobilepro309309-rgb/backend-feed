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
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'subject_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->foreignId('subject_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('subjects')
                    ->nullOnDelete();
                if ($tableName === 'interactive_videos') {
                    $table->index('subject_id');
                } else {
                    $table->index(['subject_id', 'status']);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'subject_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign(['subject_id']);
                if ($tableName === 'interactive_videos') {
                    $table->dropIndex(['subject_id']);
                } else {
                    $table->dropIndex(['subject_id', 'status']);
                }
                $table->dropColumn('subject_id');
            });
        }
    }
};
