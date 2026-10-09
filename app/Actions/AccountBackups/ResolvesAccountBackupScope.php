<?php

namespace App\Actions\AccountBackups;

use App\Actions\Provisioning\EnsuresAccountNodeIdentity;
use App\Models\Account;
use App\Models\Node;

/**
 * What an account has on one node, in the shape the node's per-account backup takes: the
 * account's own system user there and the resources a backup or restore may cover. The node
 * re-validates every value and builds every path itself, so nothing here names a path.
 */
class ResolvesAccountBackupScope
{
    /**
     * @return array{username: string, web_resources: list<string>, mail_domains: list<string>, databases: list<string>}
     */
    public function for(Account $account, Node $node): array
    {
        $identity = app(EnsuresAccountNodeIdentity::class)->handle($account, $node);

        return [
            'username' => $identity->system_username,
            'web_resources' => array_values($account->webDomains()->where('node_id', $node->id)->orderBy('id')->pluck('uuid')->all()),
            'mail_domains' => array_values($account->mailDomains()->where('node_id', $node->id)->orderBy('id')->pluck('domain')->all()),
            'databases' => array_values($account->tenantDatabases()->where('node_id', $node->id)->orderBy('id')->pluck('database_name')->all()),
        ];
    }

    /**
     * The parts a scope actually has something for.
     *
     * @param  array{username: string, web_resources: list<string>, mail_domains: list<string>, databases: list<string>}  $scope
     * @return list<string>
     */
    public function availableParts(array $scope): array
    {
        return array_values(array_filter([
            $scope['web_resources'] !== [] ? 'files' : null,
            $scope['databases'] !== [] ? 'databases' : null,
            $scope['mail_domains'] !== [] ? 'mail' : null,
        ]));
    }

    /**
     * Nodes where the account has anything to back up.
     *
     * @return list<Node>
     */
    public function nodesWithData(Account $account): array
    {
        $ids = collect()
            ->merge($account->webDomains()->pluck('node_id'))
            ->merge($account->mailDomains()->pluck('node_id'))
            ->merge($account->tenantDatabases()->pluck('node_id'))
            ->unique()
            ->values();

        return array_values(Node::query()->whereIn('id', $ids)->orderBy('name')->get()->all());
    }
}
