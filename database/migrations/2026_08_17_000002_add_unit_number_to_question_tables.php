<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'multiple_choice_questions',
            'true_false_questions',
            'cloud_capsule_challenges',
            'comparison_challenges',
            'daily_challenges',
            'find_the_bug_challenges',
            'live_duel_challenges',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'unit_number')) {
                    $table->unsignedTinyInteger('unit_number')->nullable()->after('term');
                    $table->index('unit_number');
                }
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'multiple_choice_questions',
            'true_false_questions',
            'cloud_capsule_challenges',
            'comparison_challenges',
            'daily_challenges',
            'find_the_bug_challenges',
            'live_duel_challenges',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'unit_number')) {
                    $table->dropColumn('unit_number');
                }
            });
        }
    }
};
