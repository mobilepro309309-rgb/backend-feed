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
        if (Schema::hasColumn('posts', 'status')) {
            DB::statement("ALTER TABLE posts MODIFY status ENUM('draft', 'pending', 'published') NOT NULL DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('posts', 'status')) {
            DB::statement("ALTER TABLE posts MODIFY status ENUM('draft', 'pending', 'published') NOT NULL DEFAULT 'published'");
        }
    }
};