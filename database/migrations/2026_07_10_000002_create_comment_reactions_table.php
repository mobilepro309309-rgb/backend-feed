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
        if (Schema::hasTable('comment_reactions')) {
            return;
        }

        Schema::create('comment_reactions', function (Blueprint $table) {
            // ==========================================
            // CORE STRUCTURAL COLUMNS
            // ==========================================
            $table->bigIncrements('id');
            
            // Foreign key to the comment being reacted to
            $table->unsignedBigInteger('comment_id')->index();
            $table->foreign('comment_id')->references('id')->on('post_comments')->onDelete('cascade');
            
            // The user who performed the reaction
            $table->unsignedBigInteger('user_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // ==========================================
            // REACTION TYPE COLUMN
            // ==========================================
            
            // Reaction type: 'love', 'like', 'star', 'save', or future custom types
            // Using ENUM for data integrity and rapid filtering
            $table->enum('type', ['love', 'like', 'star', 'save'])->default('like');
            
            // ==========================================
            // AUDIT & TIMESTAMPS
            // ==========================================
            $table->timestamps();

            // ==========================================
            // CONSTRAINTS & INDEXES
            // ==========================================
            
            // Ensure one reaction per user per comment (no duplicate reactions)
            $table->unique(['comment_id', 'user_id', 'type']);
            
            // Composite index for rapid retrieval of all reactions on a comment
            $table->index(['comment_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comment_reactions');
    }
};
