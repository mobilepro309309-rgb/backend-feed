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
        if (! Schema::hasTable('chats')) {
            return;
        }

        Schema::table('friendships', function (Blueprint $table) {
            if (! Schema::hasColumn('friendships', 'chat_id')) {
                $table->foreignId('chat_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('chats')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('friendships', function (Blueprint $table) {
            $table->dropForeign(['chat_id']);
            $table->dropColumn('chat_id');
        });
    }
};
