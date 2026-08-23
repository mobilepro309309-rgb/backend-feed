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
        Schema::table('interactive_videos', function (Blueprint $table) {
            if (!Schema::hasColumn('interactive_videos', 'term')) {
                $table->string('term')->nullable()->after('school_grade');
            }
            if (!Schema::hasColumn('interactive_videos', 'unit_number')) {
                $table->unsignedTinyInteger('unit_number')->nullable()->after('term');
                $table->index('unit_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interactive_videos', function (Blueprint $table) {
            if (Schema::hasColumn('interactive_videos', 'unit_number')) {
                $table->dropColumn('unit_number');
            }
            if (Schema::hasColumn('interactive_videos', 'term')) {
                $table->dropColumn('term');
            }
        });
    }
};
