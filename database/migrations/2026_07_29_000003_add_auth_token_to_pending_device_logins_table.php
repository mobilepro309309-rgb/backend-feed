<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_device_logins', function (Blueprint $table) {
            if (! Schema::hasColumn('pending_device_logins', 'auth_token')) {
                $table->text('auth_token')->nullable()->after('reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pending_device_logins', function (Blueprint $table) {
            if (Schema::hasColumn('pending_device_logins', 'auth_token')) {
                $table->dropColumn('auth_token');
            }
        });
    }
};
