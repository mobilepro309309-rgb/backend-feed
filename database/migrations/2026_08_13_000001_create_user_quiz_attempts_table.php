<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('quiz_type'); // 'multiple_choice', 'true_false', 'daily_challenge', 'find_the_bug', 'live_duel'
            $table->unsignedBigInteger('quiz_id'); // The ID of the specific quiz/question/challenge
            $table->text('user_answer')->nullable(); // JSON or string representation of user's answer
            $table->boolean('is_correct')->default(false);
            $table->timestamps();

            // Create a unique index to prevent duplicate attempts per user per quiz
            $table->unique(['user_id', 'quiz_type', 'quiz_id']);

            // Indexing for faster queries
            $table->index(['user_id', 'quiz_type']);
            $table->index(['quiz_type', 'quiz_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_quiz_attempts');
    }
};
