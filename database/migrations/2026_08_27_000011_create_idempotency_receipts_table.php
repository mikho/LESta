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
        Schema::create('idempotency_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->string('idempotency_key');
            $table->string('status');
            $table->nullableMorphs('actor');
            $table->uuid('correlation_id')->index();
            $table->json('response')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_receipts');
    }
};
