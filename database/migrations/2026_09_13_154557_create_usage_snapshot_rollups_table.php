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
        Schema::create('usage_snapshot_rollups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->morphs('snapshotable');
            $table->date('period');
            $table->unsignedBigInteger('disk_bytes_last')->nullable();
            $table->unsignedBigInteger('request_count_sum')->nullable();
            $table->unsignedBigInteger('bytes_sent_sum')->nullable();
            $table->timestamps();

            $table->unique(['snapshotable_type', 'snapshotable_id', 'period'], 'usage_snapshot_rollups_resource_period_unique');
            $table->index(['account_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_snapshot_rollups');
    }
};
