<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the one hidden "LESta platform" account: it owns node-level resources a provider
     * needs but no customer does (the web domain for each node's own hostname, which hosts
     * webmail and Adminer). It has no members and is never listed, suspended or deleted like a
     * customer account.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_platform')->default(false)->after('reseller_account_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_platform');
        });
    }
};
