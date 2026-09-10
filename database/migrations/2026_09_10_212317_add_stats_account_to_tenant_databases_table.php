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
        // A companion least-privilege read-only MariaDB account per tenant
        // database, per the Foundations decision log: "statistics reads
        // tenant-database state directly, through a dedicated least-
        // privilege read-only database account, in addition to web logs,
        // not web logs alone." stats_user is derived as database_user with
        // a "_ro" suffix (TenantDatabase::deriveStatsUsername), so 70 chars
        // covers the 64-char database_user plus that suffix comfortably.
        // stats_password is encrypted at rest identically to password (see
        // TenantDatabase::casts()).
        Schema::table('tenant_databases', function (Blueprint $table) {
            $table->string('stats_user', 70)->after('database_user');
            $table->text('stats_password')->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_databases', function (Blueprint $table) {
            $table->dropColumn(['stats_user', 'stats_password']);
        });
    }
};
