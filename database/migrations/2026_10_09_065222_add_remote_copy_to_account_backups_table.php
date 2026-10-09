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
        Schema::table('account_backups', function (Blueprint $table) {
            $table->string('remote_status', 10)->nullable()->after('last_restore_error');
            $table->string('remote_key', 512)->nullable()->after('remote_status');
            $table->text('remote_error')->nullable()->after('remote_key');
            $table->timestamp('remote_at')->nullable()->after('remote_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_backups', function (Blueprint $table) {
            $table->dropColumn(['remote_status', 'remote_key', 'remote_error', 'remote_at']);
        });
    }
};
