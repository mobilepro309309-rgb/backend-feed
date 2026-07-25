<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('feeds', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->morphs('feedable');
            $table->boolean('is_pinned')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index([
                'feedable_type',
                'feedable_id',
                'status',
                'created_at',
            ], 'feeds_feedable_type_id_status_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('feeds');
    }
};
