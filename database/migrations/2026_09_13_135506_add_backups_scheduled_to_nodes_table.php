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
            // Opt-in, per-node: an admin must explicitly turn this on, never a blanket
            // every-capable-node default, since scheduled backups consume real disk on that
            // node without further consent otherwise.
            $table->boolean('backups_scheduled')->default(false)->after('hostname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('backups_scheduled');
        });
    }
};
