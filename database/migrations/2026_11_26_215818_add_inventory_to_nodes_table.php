<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disk usage per file system and the installed stack packages, as the node last reported them
     * in a heartbeat.
     */
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->json('filesystems')->nullable();
            $table->json('packages')->nullable();
            $table->timestamp('inventory_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['filesystems', 'packages', 'inventory_reported_at']);
        });
    }
};
