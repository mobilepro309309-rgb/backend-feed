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
        Schema::create('cloud_capsule_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->string('subject', 120)->nullable();
            $table->text('intro_text')->nullable();
            $table->string('file_url')->nullable();
            $table->string('badge_text', 120)->nullable();
            $table->text('reveal_text')->nullable();
            $table->text('tip_text')->nullable();
            $table->text('mood_text')->nullable();
            $table->string('reveal_label', 120)->nullable();
            $table->string('icon', 50)->default('cloud');
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('subject');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cloud_capsule_challenges');
    }
};
