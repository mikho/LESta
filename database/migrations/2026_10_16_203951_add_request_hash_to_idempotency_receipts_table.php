<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A digest of the request a key was first used with, so a retry with the same key but another
     * body is refused instead of replayed.
     */
    public function up(): void
    {
        Schema::table('idempotency_receipts', function (Blueprint $table) {
            $table->string('request_hash', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_receipts', function (Blueprint $table) {
            $table->dropColumn('request_hash');
        });
    }
};
