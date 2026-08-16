<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, rename points to reward_points
        if (Schema::hasColumn('question_type_settings', 'points')) {
            Schema::table('question_type_settings', function (Blueprint $table) {
                $table->renameColumn('points', 'reward_points');
            });
        }

        // Then add entry_fee column if it doesn't exist
        if (!Schema::hasColumn('question_type_settings', 'entry_fee')) {
            Schema::table('question_type_settings', function (Blueprint $table) {
                $table->unsignedInteger('entry_fee')->default(0)->after('reward_points');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove entry_fee column
        if (Schema::hasColumn('question_type_settings', 'entry_fee')) {
            Schema::table('question_type_settings', function (Blueprint $table) {
                $table->dropColumn('entry_fee');
            });
        }

        // Rename reward_points back to points
        if (Schema::hasColumn('question_type_settings', 'reward_points')) {
            Schema::table('question_type_settings', function (Blueprint $table) {
                $table->renameColumn('reward_points', 'points');
            });
        }
    }
};
