<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;

class IssueNodeEnrollmentToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lesta:nodes:issue-enrollment-token {node_uuid : The uuid of the node to issue an enrollment token for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Issue a one-time, 30-minute enrollment token for a node, printed once to the console.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $node = Node::query()->where('uuid', $this->argument('node_uuid'))->firstOrFail();

        $token = $node->issueEnrollmentToken();

        $this->info("Enrollment token for node {$node->uuid} (valid 30 minutes): {$token}");
        $this->warn('This token is shown only once and is not recoverable; issue a new one if it is lost.');

        return self::SUCCESS;
    }
}
