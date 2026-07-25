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
        if (! Schema::hasColumn('users', 'governorate')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('governorate')->nullable()->after('password');
            });
        }

        if (! Schema::hasColumn('users', 'district')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('district')->nullable()->after('governorate');
            });
        }

        if (! Schema::hasColumn('users', 'village')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('village')->nullable()->after('district');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'governorate')) {
                $table->dropColumn('governorate');
            }

            if (Schema::hasColumn('users', 'district')) {
                $table->dropColumn('district');
            }

            if (Schema::hasColumn('users', 'village')) {
                $table->dropColumn('village');
            }
        });
    }
};
