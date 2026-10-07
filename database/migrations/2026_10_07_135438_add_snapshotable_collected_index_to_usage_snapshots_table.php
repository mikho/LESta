<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin usage summary asks, per resource, for its newest reading (a NOT EXISTS lookup on
     * snapshotable type and id ordered by collected_at). Without this index that lookup scans every
     * reading of the resource, which grows daily; with it, it is a single index seek.
     * The explicit name keeps it inside MariaDB's 64-character identifier limit.
     */
    public function up(): void
    {
        Schema::table('usage_snapshots', function (Blueprint $table) {
            $table->index(['snapshotable_type', 'snapshotable_id', 'collected_at'], 'usage_snapshots_snapshotable_collected_index');
        });
    }

    public function down(): void
    {
        Schema::table('usage_snapshots', function (Blueprint $table) {
            $table->dropIndex('usage_snapshots_snapshotable_collected_index');
        });
    }
};
