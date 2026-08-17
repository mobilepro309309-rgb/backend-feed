<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Convert message_type enum to a flexible string field so new message types do not require repeated DB migrations.
        DB::statement('ALTER TABLE `messages` MODIFY COLUMN `message_type` VARCHAR(255) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Revert message_type back to a narrow enum. This may fail if values outside the enum exist.
        DB::statement("ALTER TABLE `messages` MODIFY COLUMN `message_type` ENUM('text','image','video','audio','document','media','post','ComparisonCardQuiz','CheatSheetFlipCardQuiz') NULL");
    }
};
