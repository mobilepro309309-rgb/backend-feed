<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_devices')) {
            Schema::create('user_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('fcm_token', 500);
                $table->string('device_type')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'fcm_token']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};