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
        Schema::table('posts', function (Blueprint $table) {
            $table->integer('votes_score')->default(0)->after('status');
            $table->unsignedInteger('upvotes_count')->default(0)->after('votes_score');
            $table->unsignedInteger('downvotes_count')->default(0)->after('upvotes_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['votes_score', 'upvotes_count', 'downvotes_count']);
        });
    }
};
