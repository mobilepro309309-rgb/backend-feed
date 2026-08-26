<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('multiple_choice_questions', 'explanation')) {
            Schema::table('multiple_choice_questions', function (Blueprint $table): void {
                $table->text('explanation')
                    ->nullable()
                    ->after('question');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('multiple_choice_questions', 'explanation')) {
            Schema::table('multiple_choice_questions', function (Blueprint $table): void {
                $table->dropColumn('explanation');
            });
        }
    }
};
