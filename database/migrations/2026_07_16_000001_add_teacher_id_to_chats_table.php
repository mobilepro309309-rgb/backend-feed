<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chats', 'teacher_id')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->unsignedBigInteger('teacher_id')->nullable()->after('type');
            });
        }

        if (Schema::hasColumn('chats', 'teacher_id') && ! Schema::hasIndex('chats', 'chats_teacher_id_index')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->index('teacher_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            if (Schema::hasIndex('chats', 'chats_teacher_id_index')) {
                $table->dropIndex(['teacher_id']);
            }

            if (Schema::hasColumn('chats', 'teacher_id')) {
                $table->dropColumn('teacher_id');
            }
        });
    }
};
