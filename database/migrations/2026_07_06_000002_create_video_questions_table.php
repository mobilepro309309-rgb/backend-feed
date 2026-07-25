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
        Schema::create('video_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interactive_video_id')
                ->constrained('interactive_videos')
                ->cascadeOnDelete();
            $table->text('question_text');
            $table->string('choice_1');
            $table->string('choice_2');
            $table->string('choice_3');
            $table->string('choice_4');
            $table->tinyInteger('correct_choice')->unsigned();
            $table->unsignedInteger('stop_minute')->default(0);
            $table->unsignedInteger('stop_second')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_questions');
    }
};
