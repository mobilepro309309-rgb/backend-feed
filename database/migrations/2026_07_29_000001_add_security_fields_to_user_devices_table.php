<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('user_devices', 'trusted')) {
                $table->boolean('trusted')->default(true)->after('device_type');
            }
            if (! Schema::hasColumn('user_devices', 'access_token_id')) {
                $table->unsignedBigInteger('access_token_id')->nullable()->after('trusted');
            }
            if (! Schema::hasColumn('user_devices', 'device_identifier')) {
                $table->string('device_identifier')->nullable()->after('access_token_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            if (Schema::hasColumn('user_devices', 'device_identifier')) {
                $table->dropColumn('device_identifier');
            }
            if (Schema::hasColumn('user_devices', 'access_token_id')) {
                $table->dropColumn('access_token_id');
            }
            if (Schema::hasColumn('user_devices', 'trusted')) {
                $table->dropColumn('trusted');
            }
        });
    }
};
