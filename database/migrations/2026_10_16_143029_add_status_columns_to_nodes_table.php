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
            // The systemd units the node last reported with its heartbeat, [{name, state}].
            $table->json('service_states')->nullable()->after('last_seen_at');
            // The control plane's own ICMP probe of the node (nodes:ping, every minute).
            $table->boolean('ping_ok')->nullable()->after('service_states');
            $table->decimal('ping_ms', 8, 2)->nullable()->after('ping_ok');
            $table->timestamp('pinged_at')->nullable()->after('ping_ms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['service_states', 'ping_ok', 'ping_ms', 'pinged_at']);
        });
    }
};
