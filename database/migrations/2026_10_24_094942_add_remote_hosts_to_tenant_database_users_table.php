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
        Schema::table('tenant_database_users', function (Blueprint $table) {
            // The addresses the user may also connect from over the network,
            // each a single IPv4 or IPv6 address. Null or empty: local only.
            $table->json('remote_hosts')->nullable()->after('access');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_database_users', function (Blueprint $table) {
            $table->dropColumn('remote_hosts');
        });
    }
};
