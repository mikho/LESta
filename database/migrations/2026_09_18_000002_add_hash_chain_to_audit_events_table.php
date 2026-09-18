<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * audit_events was a plain, fully mutable table: no application route or policy exposes
     * editing or deleting a row today, but nothing at the data layer would reveal it either, if a
     * compromised DB credential (or a future bug reaching this table) ever did. hash chains each
     * row to the one before it (see AuditEvent's own creating() hook), so altering or deleting any
     * existing row breaks the chain from that point forward -- detectable by
     * php artisan audit:verify-chain, even though still not preventable at the database layer
     * alone.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->string('previous_hash', 64)->nullable()->after('user_agent');
            $table->string('hash', 64)->nullable()->after('previous_hash');
        });

        // Backfills existing rows in their real insertion order (id ascending) so the chain
        // covers pre-existing history too, not just rows created from this point forward.
        $previousHash = null;

        // created_at is deliberately not part of the hashed material: AuditEvent's own
        // creating() hook (the one place that computes this for every real row from here on)
        // runs before Model::performInsert() assigns timestamps, so created_at does not exist
        // yet at hashing time there -- excluding it here too keeps the backfill and the ongoing
        // hook computing the exact same thing for the exact same row.
        DB::table('audit_events')->orderBy('id')->each(function ($event) use (&$previousHash) {
            $hash = hash('sha256', implode('|', [
                (string) $previousHash,
                (string) $event->actor_type,
                (string) $event->actor_id,
                (string) $event->auditable_type,
                (string) $event->auditable_id,
                (string) $event->action,
                (string) $event->correlation_id,
                (string) $event->metadata,
            ]));

            DB::table('audit_events')->where('id', $event->id)->update([
                'previous_hash' => $previousHash,
                'hash' => $hash,
            ]);

            $previousHash = $hash;
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropColumn(['previous_hash', 'hash']);
        });
    }
};
