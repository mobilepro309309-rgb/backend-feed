<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banned_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_identifier')->unique();
            $table->string('reason')->nullable();
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('device_identifier');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banned_devices');
    }
};
