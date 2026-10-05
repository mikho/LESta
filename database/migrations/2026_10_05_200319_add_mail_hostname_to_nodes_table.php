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
            // The hostname this node's own mail stack identifies as (the same value passed to
            // .install/services/mail/install.sh and webmail/install.sh as --mail-hostname).
            // Webmail is served at https://<mail_hostname>/ only.
            $table->string('mail_hostname')->nullable()->after('hostname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('mail_hostname');
        });
    }
};
