<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mail_domains', function (Blueprint $table) {
            // dkim_selector was a fixed Go-side "lesta1" constant before real
            // rotation existed; every domain that already has a real generated key
            // already has it under exactly that filename, so 'lesta1' is the only
            // correct default here, not an arbitrary placeholder.
            $table->string('dkim_selector')->default('lesta1');
            $table->timestamp('dkim_selector_activated_at')->nullable();
            $table->string('dkim_pending_selector')->nullable();
            $table->timestamp('dkim_pending_selector_published_at')->nullable();
            $table->string('dkim_retiring_selector')->nullable();
            $table->timestamp('dkim_retiring_selector_demoted_at')->nullable();
        });

        // Backfill the rotation clock for domains that already have DKIM enabled:
        // their real enable date isn't recorded anywhere, so "now" is the only
        // honest starting point -- their first automatic rotation simply lands
        // one full interval from today instead of from whenever they actually
        // first enabled it.
        DB::table('mail_domains')
            ->where('dkim_enabled', true)
            ->update(['dkim_selector_activated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_domains', function (Blueprint $table) {
            $table->dropColumn([
                'dkim_selector',
                'dkim_selector_activated_at',
                'dkim_pending_selector',
                'dkim_pending_selector_published_at',
                'dkim_retiring_selector',
                'dkim_retiring_selector_demoted_at',
            ]);
        });
    }
};
