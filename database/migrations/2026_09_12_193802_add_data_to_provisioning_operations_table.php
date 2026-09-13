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
        // Optional, capability-specific derived non-secret data a node reports back
        // alongside a terminal result (see docs/protocol/result-envelope.schema.json's
        // own "data" property and agent/internal/protocol/envelope.go's own doc
        // comment): e.g. backup.encrypted-artifacts.v1's own artifact path/size/
        // checksum/included-capabilities. Never populated for a rejected/failed
        // result today, and never a place a secret is allowed to land.
        Schema::table('provisioning_operations', function (Blueprint $table) {
            $table->json('data')->nullable()->after('errors');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provisioning_operations', function (Blueprint $table) {
            $table->dropColumn('data');
        });
    }
};
