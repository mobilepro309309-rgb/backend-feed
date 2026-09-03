<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duel_participants', function (Blueprint $table) {
            $table->unsignedInteger('answered_count')->default(0);
            $table->json('answered_questions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('duel_participants', function (Blueprint $table) {
            $table->dropColumn(['answered_count', 'answered_questions']);
        });
    }
};
