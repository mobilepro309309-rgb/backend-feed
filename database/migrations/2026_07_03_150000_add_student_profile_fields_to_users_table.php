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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('school_grade', [
                'اولى',
                'ثانية',
                'ثالثة',
                'اعدادي',
                'ثانوي',
            ])->nullable()->after('role');

            $table->enum('gender', [
                'ولد',
                'بنت',
            ])->nullable()->after('school_grade');

            $table->string('location')->nullable()->after('gender');

            $table->enum('theme_mode', [
                'light',
                'dark',
            ])->default('light')->after('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'school_grade')) {
                $table->dropColumn('school_grade');
            }

            if (Schema::hasColumn('users', 'gender')) {
                $table->dropColumn('gender');
            }

            if (Schema::hasColumn('users', 'location')) {
                $table->dropColumn('location');
            }

            if (Schema::hasColumn('users', 'theme_mode')) {
                $table->dropColumn('theme_mode');
            }
        });
    }
};
