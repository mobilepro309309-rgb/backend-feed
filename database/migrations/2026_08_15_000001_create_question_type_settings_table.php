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
        Schema::create('question_type_settings', function (Blueprint $table) {
            $table->id();
            $table->string('question_type')->unique();
            $table->unsignedInteger('points')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('question_type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_type_settings');
    }
};
