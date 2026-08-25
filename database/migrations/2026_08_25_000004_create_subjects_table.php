<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->nullable()->constrained('grades')->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained('tracks')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('education_type')->default('general');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};