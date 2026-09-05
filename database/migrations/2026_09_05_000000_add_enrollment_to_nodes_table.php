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
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('enrollment_token_hash')->nullable();
            $table->timestamp('enrollment_token_expires_at')->nullable();
            $table->string('node_credential_hash')->nullable();
            $table->string('enrollment_status')->default('pending');
            $table->string('protocol_version')->nullable();
            $table->string('agent_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn([
                'enrollment_token_hash',
                'enrollment_token_expires_at',
                'node_credential_hash',
                'enrollment_status',
                'protocol_version',
                'agent_version',
                'last_seen_at',
            ]);
        });
    }
};
