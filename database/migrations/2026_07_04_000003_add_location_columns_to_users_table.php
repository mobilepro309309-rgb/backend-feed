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
        if (! Schema::hasColumn('users', 'location')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('location')->nullable()->after('village');
            });
        }

        if (! Schema::hasColumn('users', 'latitude')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            });
        }

        if (! Schema::hasColumn('users', 'longitude')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }

        if (! Schema::hasColumn('users', 'geolocation_updated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('geolocation_updated_at')->nullable()->after('longitude');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'geolocation_updated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('geolocation_updated_at');
            });
        }

        if (Schema::hasColumn('users', 'longitude')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('longitude');
            });
        }

        if (Schema::hasColumn('users', 'latitude')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('latitude');
            });
        }

        if (Schema::hasColumn('users', 'location')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('location');
            });
        }
    }
};
