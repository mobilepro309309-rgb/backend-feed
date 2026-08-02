<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
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
                if (!Schema::hasColumn($tableName, 'school_grade')) {
                    $table->string('school_grade')->nullable()->after('subject');
                }

                if (!Schema::hasColumn($tableName, 'term')) {
                    $table->unsignedTinyInteger('term')->default(1)->after('school_grade');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
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
                if (Schema::hasColumn($tableName, 'term')) {
                    $table->dropColumn('term');
                }

                if (Schema::hasColumn($tableName, 'school_grade')) {
                    $table->dropColumn('school_grade');
                }
            });
        }
    }
};
