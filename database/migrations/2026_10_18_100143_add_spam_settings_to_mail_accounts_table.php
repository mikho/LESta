<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each mailbox's own spam settings: how readily mail is filed into Junk
     * (off, low, medium, high) and its own sender allow and block lists.
     */
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->string('spam_sensitivity', 8)->default('medium')->after('autoreply_message');
            $table->json('spam_allow')->nullable()->after('spam_sensitivity');
            $table->json('spam_block')->nullable()->after('spam_allow');
        });
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn(['spam_sensitivity', 'spam_allow', 'spam_block']);
        });
    }
};
