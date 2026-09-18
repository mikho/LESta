<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use Illuminate\Console\Command;

/**
 * Walks audit_events in insertion order, recomputing each row's own hash from its real stored
 * fields plus the previous row's real stored hash (the exact same formula AuditEvent's own
 * creating() hook already used when the row was first written -- see that hook's own doc comment
 * for why created_at is deliberately excluded), and reports the first row where either the
 * recomputed hash does not match what's stored, or previous_hash does not match the prior row's
 * own stored hash. Either mismatch means that row (or one before it) was altered, or a row was
 * deleted outright, after it was first written -- this table has no application route or policy
 * exposing edit/delete, so a real mismatch here means something reached the database directly.
 */
class VerifyAuditChain extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:verify-chain';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify the audit_events hash chain has not been tampered with.';

    public function handle(): int
    {
        $previousHash = null;
        $checked = 0;
        $brokenAt = null;

        AuditEvent::query()->orderBy('id')->chunkById(500, function ($events) use (&$previousHash, &$checked, &$brokenAt): bool {
            foreach ($events as $event) {
                $checked++;

                $expectedHash = hash('sha256', implode('|', [
                    (string) $previousHash,
                    (string) $event->actor_type,
                    (string) $event->actor_id,
                    (string) $event->auditable_type,
                    (string) $event->auditable_id,
                    (string) $event->action,
                    (string) $event->correlation_id,
                    (string) ($event->getAttributes()['metadata'] ?? null),
                ]));

                if ($event->previous_hash !== $previousHash || $event->hash !== $expectedHash) {
                    $brokenAt = $event->id;

                    return false;
                }

                $previousHash = $event->hash;
            }

            return true;
        });

        if ($brokenAt !== null) {
            $this->error("Audit chain broken at audit_events.id={$brokenAt} (checked {$checked} row(s) before stopping). This row, or one before it, was altered or removed after it was first written.");

            return self::FAILURE;
        }

        $this->info("Audit chain verified intact across {$checked} row(s).");

        return self::SUCCESS;
    }
}
