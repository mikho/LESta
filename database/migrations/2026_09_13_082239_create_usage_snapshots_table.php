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
        Schema::create('usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->morphs('snapshotable');
            $table->unsignedBigInteger('disk_bytes')->nullable();
            $table->unsignedBigInteger('request_count')->nullable();
            $table->unsignedBigInteger('bytes_sent')->nullable();
            $table->timestamp('collected_at');
            $table->timestamps();

            $table->index(['account_id', 'collected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_snapshots');
    }
};
