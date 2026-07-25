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
        if (Schema::hasColumn('user_addresses', 'village_id')) {
            Schema::table('user_addresses', function (Blueprint $table) {
                $table->dropForeign(['village_id']);
            });

            if (Schema::hasIndex('user_addresses', 'user_addresses_village_id_index')) {
                Schema::table('user_addresses', function (Blueprint $table) {
                    $table->dropIndex('user_addresses_village_id_index');
                });
            }
        }

        Schema::table('user_addresses', function (Blueprint $table) {
            if (Schema::hasColumn('user_addresses', 'village_id')) {
                $table->dropColumn('village_id');
            }

            if (Schema::hasColumn('user_addresses', 'street_details')) {
                $table->dropColumn('street_details');
            }

            if (!Schema::hasColumn('user_addresses', 'governorate')) {
                $table->string('governorate')->nullable()->after('user_id');
            }

            if (!Schema::hasColumn('user_addresses', 'city_or_center')) {
                $table->string('city_or_center')->nullable()->after('governorate');
            }

            if (!Schema::hasColumn('user_addresses', 'village_name')) {
                $table->string('village_name')->nullable()->after('city_or_center');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_addresses', function (Blueprint $table) {
            if (Schema::hasColumn('user_addresses', 'governorate')) {
                $table->dropColumn('governorate');
            }

            if (Schema::hasColumn('user_addresses', 'city_or_center')) {
                $table->dropColumn('city_or_center');
            }

            if (Schema::hasColumn('user_addresses', 'village_name')) {
                $table->dropColumn('village_name');
            }

            if (!Schema::hasColumn('user_addresses', 'village_id')) {
                $table->foreignId('village_id')->nullable()->constrained('villages')->cascadeOnDelete();
            }

            if (!Schema::hasColumn('user_addresses', 'street_details')) {
                $table->string('street_details', 255)->nullable();
            }
        });
    }
};
