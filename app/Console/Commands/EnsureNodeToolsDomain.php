<?php

namespace App\Console\Commands;

use App\Actions\Nodes\EnsureNodeToolsDomain as EnsureNodeToolsDomainAction;
use App\Models\Node;
use Illuminate\Console\Command;

class EnsureNodeToolsDomain extends Command
{
    /**
     * @var string
     */
    protected $signature = 'lesta:nodes:ensure-tools-domain {node : The uuid or name of the node} {--ssl-mode=lets_encrypt : lets_encrypt, manual or none}';

    /**
     * @var string
     */
    protected $description = "Create the web domain for a node's own hostname (owned by the hidden platform account), which hosts webmail and Adminer.";

    public function handle(EnsureNodeToolsDomainAction $action): int
    {
        $reference = (string) $this->argument('node');
        $node = Node::query()->where('uuid', $reference)->orWhere('name', $reference)->firstOrFail();

        $sslMode = (string) $this->option('ssl-mode');

        if (! in_array($sslMode, ['lets_encrypt', 'manual', 'none'], true)) {
            $this->error('--ssl-mode must be lets_encrypt, manual or none.');

            return self::INVALID;
        }

        $webDomain = $action->handle($node, $sslMode);

        $this->info("Web domain {$webDomain->domain} exists on node {$node->name} (ssl mode: {$webDomain->ssl_mode->value}).");
        $this->line('Webmail and Adminer become available on it once it has an issued certificate.');

        return self::SUCCESS;
    }
}
