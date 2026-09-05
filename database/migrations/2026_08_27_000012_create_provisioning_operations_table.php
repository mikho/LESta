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
        Schema::create('provisioning_operations', function (Blueprint $table) {
            $table->id();
            // Explicit, shortened index name: Blueprint::morphs()'s own auto-generated name
            // ("provisioning_operations_provisionable_type_provisionable_id_index", 69 chars)
            // exceeds MySQL/MariaDB's 64-character identifier limit. sqlite has no such limit,
            // so this only surfaces once tests actually run against real MariaDB (ADR 0002).
            $table->morphs('provisionable', 'provisioning_operations_provisionable_index');
            $table->uuid('resource_id')->index();
            $table->string('capability');
            $table->string('operation');
            $table->string('status')->index();
            $table->unsignedInteger('desired_state_version');
            $table->json('payload');
            $table->string('protocol_version')->default('1');
            $table->uuid('correlation_id')->index();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('deadline')->nullable();
            $table->timestamp('issued_at');
            $table->string('request_digest');
            $table->unsignedInteger('observed_state_version')->nullable();
            $table->string('observed_state_digest')->nullable();
            $table->string('generation_id')->nullable();
            $table->json('errors')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provisioning_operations');
    }
};
