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
        Schema::create('subject_units', function (Blueprint $table) {
            $table->id();
            $table->string('school_grade');
            $table->string('subject');
            $table->unsignedInteger('total_units')->default(0);
            $table->timestamps();

            // Store numeric grade level directly: 1, 2, or 3 for preparatory stages.

            $table->unique(['school_grade', 'subject']);
            $table->index(['school_grade', 'subject']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subject_units');
    }
};
