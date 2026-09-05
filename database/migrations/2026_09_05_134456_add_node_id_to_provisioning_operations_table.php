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
            // Nullable: some provisionables (e.g. Account, used by tests and any
            // node-agnostic operation) never have a node_id of their own, unlike
            // WebDomain/DnsZone/TenantDatabase/CronJob. restrictOnDelete, not
            // cascadeOnDelete, mirrors those tables' own node_id FK convention:
            // a node is never deleted out from under its own operations.
            $table->foreignId('node_id')->nullable()->after('provisionable_id')->constrained('nodes')->restrictOnDelete();
            $table->index(['node_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provisioning_operations', function (Blueprint $table) {
            $table->dropForeign(['node_id']);
            $table->dropIndex(['node_id', 'status']);
            $table->dropColumn('node_id');
        });
    }
};
