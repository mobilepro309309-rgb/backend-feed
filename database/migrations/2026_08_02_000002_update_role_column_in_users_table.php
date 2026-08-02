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
        if (! Schema::hasTable('users')) {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'user'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('main-admin', 'admin', 'reply_questions_admin', 'question_post_admin', 'financial_admin', 'technical_support_admin', 'user') DEFAULT 'user'");
    }
};
