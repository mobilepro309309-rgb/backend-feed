<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('stage_id')
                ->nullable()
                ->after('gender')
                ->constrained('stages')
                ->nullOnDelete();
            $table->foreignId('grade_id')
                ->nullable()
                ->after('stage_id')
                ->constrained('grades')
                ->nullOnDelete();
            $table->foreignId('track_id')
                ->nullable()
                ->after('grade_id')
                ->constrained('tracks')
                ->nullOnDelete();
            $table->foreignId('specialized_subject_id')
                ->nullable()
                ->after('track_id')
                ->constrained('specialized_subjects')
                ->nullOnDelete();
            $table->string('education_system')->default('general')->after('specialized_subject_id');
            $table->string('city_or_address')->nullable()->after('education_system');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['stage_id']);
            $table->dropForeign(['grade_id']);
            $table->dropForeign(['track_id']);
            $table->dropForeign(['specialized_subject_id']);
            $table->dropColumn([
                'stage_id',
                'grade_id',
                'track_id',
                'specialized_subject_id',
                'education_system',
                'city_or_address',
            ]);
        });
    }
};
