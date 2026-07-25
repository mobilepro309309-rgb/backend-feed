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
        if (! Schema::hasColumn('user_addresses', 'latitude')) {
            Schema::table('user_addresses', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable()->after('street_details');
            });
        }

        if (! Schema::hasColumn('user_addresses', 'longitude')) {
            Schema::table('user_addresses', function (Blueprint $table) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('user_addresses', 'longitude')) {
            Schema::table('user_addresses', function (Blueprint $table) {
                $table->dropColumn('longitude');
            });
        }

        if (Schema::hasColumn('user_addresses', 'latitude')) {
            Schema::table('user_addresses', function (Blueprint $table) {
                $table->dropColumn('latitude');
            });
        }
    }
};
