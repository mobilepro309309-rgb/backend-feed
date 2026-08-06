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
        Schema::table('interactive_videos', function (Blueprint $table) {
            $table->string('school_grade')->nullable()->after('subject');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interactive_videos', function (Blueprint $table) {
            if (Schema::hasColumn('interactive_videos', 'school_grade')) {
                $table->dropColumn('school_grade');
            }
        });
    }
};