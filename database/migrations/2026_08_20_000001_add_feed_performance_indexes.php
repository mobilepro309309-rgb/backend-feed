<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->index('status', 'posts_status_index');
            $table->index('user_id', 'posts_user_id_index');
            $table->index(['status', 'created_at'], 'posts_status_created_at_index');
        });

        if (Schema::hasColumn('posts', 'school_grade')) {
            Schema::table('posts', function (Blueprint $table): void {
                $table->index('school_grade', 'posts_school_grade_index');
            });
        }

        Schema::table('teacher_scopes', function (Blueprint $table): void {
            $table->index('school_grade', 'teacher_scopes_school_grade_index');
            $table->index(['user_id', 'school_grade'], 'teacher_scopes_user_grade_index');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex('posts_status_index');
            $table->dropIndex('posts_user_id_index');
            $table->dropIndex('posts_status_created_at_index');
        });

        if (Schema::hasColumn('posts', 'school_grade')) {
            Schema::table('posts', function (Blueprint $table): void {
                $table->dropIndex('posts_school_grade_index');
            });
        }

        Schema::table('teacher_scopes', function (Blueprint $table): void {
            $table->dropIndex('teacher_scopes_school_grade_index');
            $table->dropIndex('teacher_scopes_user_grade_index');
        });
    }
};