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
            $table->decimal('latitude', 10, 8)->nullable()->after('location');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->timestamp('geolocation_updated_at')->nullable()->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
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
};
