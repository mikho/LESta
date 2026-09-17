<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('node_capabilities', function (Blueprint $table): void {
            $table->string('status')->default('not_installed')->after('capability');
        });

        // A capability with a real last_seen_at has, at least once, been reported present and
        // healthy by a real agent heartbeat (the only way last_seen_at is ever set) -- treat that
        // as running rather than defaulting every existing row to not_installed, which would
        // falsely regress long-working infrastructure to "not installed" the moment this ships.
        // Deliberately not filtered by suspended_at: a suspended-but-previously-confirmed row
        // should reveal "running" underneath once unsuspended, not "not installed".
        DB::table('node_capabilities')
            ->whereNotNull('last_seen_at')
            ->update(['status' => 'running']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('node_capabilities', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
