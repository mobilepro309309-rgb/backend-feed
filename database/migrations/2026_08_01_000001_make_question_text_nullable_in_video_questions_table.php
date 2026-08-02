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
        if (Schema::hasColumn('video_questions', 'question_text')) {
            Schema::table('video_questions', function (Blueprint $table) {
                $table->text('question_text')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('video_questions', 'question_text')) {
            Schema::table('video_questions', function (Blueprint $table) {
                $table->text('question_text')->nullable(false)->change();
            });
        }
    }
};
