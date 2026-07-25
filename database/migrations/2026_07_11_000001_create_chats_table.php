<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chats')) {
            Schema::create('chats', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->enum('type', ['private', 'group'])->default('private');
                $table->timestamps();

                $table->index('type');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
