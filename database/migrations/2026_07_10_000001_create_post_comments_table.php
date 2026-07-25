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
        if (Schema::hasTable('post_comments')) {
            return;
        }

        Schema::create('post_comments', function (Blueprint $table) {
            // ==========================================
            // CORE STRUCTURAL COLUMNS
            // ==========================================
            $table->bigIncrements('id');
            
            // Foreign key to the post being commented on
            $table->unsignedBigInteger('post_id')->index();
            $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            
            // The user who authored the comment
            $table->unsignedBigInteger('user_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Self-referencing foreign key for nested replies (unlimited depth support)
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->foreign('parent_id')->references('id')->on('post_comments')->onDelete('cascade');

            // ==========================================
            // CONTENT & FUTURE-PROOFING COLUMNS (SCALABILITY)
            // ==========================================
            
            // The main comment text content
            $table->longText('content');
            
            // Comment type: 'text', 'image', 'voice', 'gif', etc.
            // Allows future expansion without schema changes
            $table->string('type')->default('text');
            
            // Flexible JSON column for rich metadata:
            // - Mentions: { mentions: [{ user_id: 1, name: 'أحمد' }] }
            // - Links preview: { links: [{ url: '...', title: '...' }] }
            // - Parsed markdown or rich-text formatting
            // - Custom fields added in future without migrations
            $table->json('metadata')->nullable();

            // ==========================================
            // AUDIT & COMPLIANCE COLUMNS
            // ==========================================
            
            // Timestamps for creation/update audit trails
            $table->timestamps();
            
            // Soft deletes for compliance: allows showing 'Comment Deleted'
            // placeholders and preserving comment hierarchy
            $table->softDeletes();

            // ==========================================
            // PERFORMANCE INDEXES
            // ==========================================
            
            // Composite index for optimized cursor-pagination queries
            // When fetching nested comments for a specific post
            $table->index(['post_id', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_comments');
    }
};
