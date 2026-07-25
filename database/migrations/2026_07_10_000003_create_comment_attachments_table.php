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
        if (Schema::hasTable('comment_attachments')) {
            return;
        }

        Schema::create('comment_attachments', function (Blueprint $table) {
            // ==========================================
            // CORE STRUCTURAL COLUMNS
            // ==========================================
            $table->bigIncrements('id');
            
            // Foreign key to the comment
            $table->unsignedBigInteger('comment_id')->index();
            $table->foreign('comment_id')->references('id')->on('post_comments')->onDelete('cascade');
            
            // ==========================================
            // ATTACHMENT METADATA COLUMNS
            // ==========================================
            
            // Original file name
            $table->string('name')->nullable();
            
            // Stored file URI/path in storage system
            $table->text('uri');
            
            // MIME type: 'image/jpeg', 'video/mp4', 'application/pdf', etc.
            $table->string('mime_type')->nullable();
            
            // File size in bytes
            $table->unsignedBigInteger('size')->default(0);
            
            // Attachment type: 'image', 'video', 'audio', 'document', etc.
            // Helps with UI rendering decisions
            $table->string('type')->default('document');
            
            // User-provided note or caption for the attachment
            $table->text('note')->nullable();
            
            // Metadata for attachments: thumbnails, dimensions, duration, etc.
            $table->json('metadata')->nullable();

            // ==========================================
            // AUDIT & TIMESTAMPS
            // ==========================================
            $table->timestamps();

            // ==========================================
            // INDEXES FOR PERFORMANCE
            // ==========================================
            
            // Quick lookup by comment
          
            
            // Optional: index by type for filtering
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comment_attachments');
    }
};
