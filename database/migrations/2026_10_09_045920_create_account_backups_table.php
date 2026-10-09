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
        Schema::create('account_backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained();
            $table->string('label', 100)->nullable();
            $table->string('kind', 20)->default('manual');
            $table->text('encryption_key');
            $table->json('requested_parts');
            $table->string('status', 20)->default('pending');
            $table->json('parts')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 80)->nullable();
            $table->string('artifact_path', 500)->nullable();
            $table->json('report')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('restore_after_id')->nullable();
            $table->json('restore_parts')->nullable();
            $table->string('last_restore_status', 20)->nullable();
            $table->timestamp('last_restore_at')->nullable();
            $table->json('last_restore_report')->nullable();
            $table->text('last_restore_error')->nullable();
            $table->unsignedInteger('desired_state_version')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'node_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_backups');
    }
};
