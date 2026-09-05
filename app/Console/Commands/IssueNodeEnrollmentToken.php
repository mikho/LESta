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

        // The new App\Actions\Nodes\IssueNodeEnrollmentToken action requires a User actor to
        // authorize and audit against, and this console command runs with no authenticated
        // user and no reliable seeded provider admin to stand in for one. Rather than invent a
        // synthetic actor (or weaken the action's signature to accept a nullable actor just for
        // this one caller), this command keeps calling the model method directly; the web path
        // (NodeController::issueEnrollmentToken) is the one that goes through the audited
        // Action, since it always has a real authenticated provider admin as its actor.
        $token = $node->issueEnrollmentToken();

        $this->info("Enrollment token for node {$node->uuid} (valid 30 minutes): {$token}");
        $this->warn('This token is shown only once and is not recoverable; issue a new one if it is lost.');

        return self::SUCCESS;
    }
}
