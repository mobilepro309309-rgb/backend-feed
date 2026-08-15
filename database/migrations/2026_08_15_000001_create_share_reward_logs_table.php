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
        Schema::create('share_reward_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('platform', ['whatsapp', 'facebook']);
            $table->date('share_day');
            $table->unsignedInteger('points_awarded')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'share_day'], 'share_reward_logs_user_platform_day_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('share_reward_logs');
    }
};
