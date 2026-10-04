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
        Schema::create('adminer_access_tokens', function (Blueprint $table) {
            $table->id();
            // Only the sha256 hash is ever stored, mirroring how a node credential is stored
            // (nodes.node_credential_hash): the raw, single-use token only ever exists in the
            // short-lived redirect URL App\Actions\TenantDatabases\PrepareAdminerSession hands
            // back, never persisted in cleartext.
            $table->string('token_hash')->unique();
            $table->foreignId('tenant_database_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_database_id', 'used_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adminer_access_tokens');
    }
};
