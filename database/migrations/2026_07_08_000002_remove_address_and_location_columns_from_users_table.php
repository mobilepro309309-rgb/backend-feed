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
            if (Schema::hasColumn('users', 'governorate')) {
                $table->dropColumn('governorate');
            }

            if (Schema::hasColumn('users', 'district')) {
                $table->dropColumn('district');
            }

            if (Schema::hasColumn('users', 'village')) {
                $table->dropColumn('village');
            }

            if (Schema::hasColumn('users', 'location')) {
                $table->dropColumn('location');
            }

            if (Schema::hasColumn('users', 'latitude')) {
                $table->dropColumn('latitude');
            }

            if (Schema::hasColumn('users', 'longitude')) {
                $table->dropColumn('longitude');
            }

            if (Schema::hasColumn('users', 'geolocation_updated_at')) {
                $table->dropColumn('geolocation_updated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'governorate')) {
                $table->string('governorate')->nullable()->after('password');
            }

            if (! Schema::hasColumn('users', 'district')) {
                $table->string('district')->nullable()->after('governorate');
            }

            if (! Schema::hasColumn('users', 'village')) {
                $table->string('village')->nullable()->after('district');
            }

            if (! Schema::hasColumn('users', 'location')) {
                $table->string('location')->nullable()->after('village');
            }

            if (! Schema::hasColumn('users', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            }

            if (! Schema::hasColumn('users', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }

            if (! Schema::hasColumn('users', 'geolocation_updated_at')) {
                $table->timestamp('geolocation_updated_at')->nullable()->after('longitude');
            }
        });
    }
};
