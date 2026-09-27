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
        Schema::table('account_node_identities', function (Blueprint $table) {
            // Tenant-supplied, per Web Application Hosting Threat Model and Isolation Design.md
            // step 1: the tenant's own SFTP login key, stored here rather than on a new model
            // since it is 1:1 with this account's own per-node identity. Nullable: an identity
            // can exist (created lazily by a cron job) with no key yet, and the real sshd
            // Match/ChrootDirectory config that would actually use this value is deliberately
            // not built until step 2 of that design's own recommended build sequence -- this
            // migration only ever stores the value, nothing consumes it on a node yet.
            $table->text('ssh_public_key')->nullable()->after('system_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_node_identities', function (Blueprint $table) {
            $table->dropColumn('ssh_public_key');
        });
    }
};
