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
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('body');
                $table->string('type')->nullable();
                $table->json('data')->nullable();
                $table->timestamps();
            });
            return;
        }

        if (! Schema::hasColumn('notifications', 'title')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('title');
            });
        }

        if (! Schema::hasColumn('notifications', 'body')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->text('body');
            });
        }

        if (! Schema::hasColumn('notifications', 'type')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('type')->nullable();
            });
        }

        if (! Schema::hasColumn('notifications', 'data')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->json('data')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
