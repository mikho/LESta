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
        Schema::table('provisioning_operations', function (Blueprint $table) {
            // When a node's fast lane took the operation (ProvisioningOperation::FAST_LANE): it is
            // handed out once, never again while it runs.
            $table->timestamp('claimed_at')->nullable()->after('dispatched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provisioning_operations', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });
    }
};
