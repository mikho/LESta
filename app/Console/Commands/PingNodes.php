<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

/**
 * Sends one ICMP echo from the control plane to every node and records whether it answered and how
 * long it took, for the admin dashboard. All nodes are probed at once, each with a two-second wait.
 * This is reachability from the panel's host only; whether the node's own daemon is working is the
 * heartbeat's job.
 */
class PingNodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nodes:ping';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ping every node from the control plane and record the round trip time.';

    public function handle(): int
    {
        $nodes = Node::query()->get(['id', 'hostname']);

        if ($nodes->isEmpty()) {
            return self::SUCCESS;
        }

        $results = Process::pool(function (Pool $pool) use ($nodes): void {
            foreach ($nodes as $node) {
                $pool->as((string) $node->id)->timeout(10)->command(['ping', '-n', '-c', '1', '-W', '2', $node->hostname]);
            }
        })->start()->wait();

        $byNode = $results->collect();

        foreach ($nodes as $node) {
            $result = $byNode->get((string) $node->id);
            $milliseconds = $result === null ? null : self::roundTrip($result->output());
            $answered = $result !== null && $result->successful() && $milliseconds !== null;

            $node->forceFill([
                'ping_ok' => $answered,
                'ping_ms' => $answered ? $milliseconds : null,
                'pinged_at' => now(),
            ])->saveQuietly();

            $this->line(sprintf('%s: %s', $node->hostname, $answered ? "{$milliseconds} ms" : 'no reply'));
        }

        return self::SUCCESS;
    }

    /**
     * The round trip time from ping's output ("time=0.412 ms"), or null when there was no reply.
     */
    public static function roundTrip(string $output): ?float
    {
        return preg_match('/time[=<]([0-9.]+) ?ms/', $output, $match) === 1 ? (float) $match[1] : null;
    }
}
