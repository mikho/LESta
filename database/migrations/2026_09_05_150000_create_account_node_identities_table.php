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
        Schema::create('account_node_identities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // restrictOnDelete, not cascadeOnDelete, matching every other account-owned
            // provisionable resource's own account_id FK convention (cron_jobs, dns_zones,
            // web_domains, tenant_databases): an account with a dedicated per-node identity
            // still provisioned on a node cannot simply vanish out from under it.
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->string('system_username');
            $table->unsignedInteger('desired_state_version')->default(1);
            $table->timestamps();

            $table->unique(['account_id', 'node_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_node_identities');
    }
};
