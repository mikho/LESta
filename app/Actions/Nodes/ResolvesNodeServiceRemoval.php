<?php

namespace App\Actions\Nodes;

use App\Enums\WebServer;
use App\Models\AccountBackup;
use App\Models\Backup;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Node;
use App\Models\TenantDatabase;
use App\Models\WebDomain;

/**
 * Which LESta services a node has that the panel may remove, and what stands in the way of removing
 * each: customer resources on the node that use it (owner's decision: refuse until they are moved),
 * or another installed service that needs it. The node checks the dependencies again itself.
 */
class ResolvesNodeServiceRemoval
{
    /** Installer service => the capabilities it provides on a node. */
    public const array SERVICES = [
        'nginx' => ['web.nginx.v1'],
        'apache' => ['web.apache.v1'],
        'bind9' => ['dns.bind9.v1'],
        'mariadb' => ['database.tenant.v1'],
        'cron' => ['scheduler.account-cron.v1'],
        'mail' => ['mail.smtp-imap.v1'],
        'backups' => ['backup.encrypted-artifacts.v1'],
        'php-fpm' => ['web.php-fpm.v1'],
        'adminer' => ['tools.adminer.v1'],
        'webmail' => ['mail.webmail.v1'],
    ];

    /** Service => the services it needs (the installer manifests' depends_on, as services). */
    public const array NEEDS = [
        'mail' => ['nginx', 'bind9'],
        'adminer' => ['php-fpm', 'nginx'],
        'webmail' => ['mail', 'php-fpm', 'nginx'],
    ];

    /** Services that hold data the panel cannot rebuild; the node moves it aside, never deletes it. */
    public const array STATEFUL = ['mariadb', 'mail', 'backups', 'webmail'];

    /**
     * @return list<string> the removable services the node has, in the order of SERVICES
     */
    public function installed(Node $node): array
    {
        $present = $node->capabilities()->pluck('capability')->all();

        return array_values(array_filter(
            array_keys(self::SERVICES),
            fn (string $service): bool => array_intersect(self::SERVICES[$service], $present) !== [],
        ));
    }

    /**
     * @return list<string> why $service cannot be removed from $node now; empty when it can
     */
    public function blockers(Node $node, string $service): array
    {
        $blockers = [];
        $installed = $this->installed($node);

        foreach ($installed as $other) {
            if ($other !== $service && in_array($service, self::NEEDS[$other] ?? [], true)) {
                $blockers[] = __('Needed by :service', ['service' => $other]);
            }
        }

        $inUse = $this->inUse($node, $service);

        if ($inUse !== null) {
            $blockers[] = __('In use by :what', ['what' => $inUse]);
        }

        return $blockers;
    }

    /**
     * What on this node still uses $service, as a short phrase, or null.
     */
    private function inUse(Node $node, string $service): ?string
    {
        $count = match ($service) {
            'nginx' => [WebDomain::query()->where('node_id', $node->id)->count(), 'web domain', 'web domains'],
            'apache' => [WebDomain::query()->where('node_id', $node->id)->where('web_server', WebServer::Apache)->count(), 'web domain on Apache', 'web domains on Apache'],
            'php-fpm' => [WebDomain::query()->where('node_id', $node->id)->whereNotNull('php_version')->count(), 'web domain with PHP', 'web domains with PHP'],
            'bind9' => [DnsZone::query()->where('node_id', $node->id)->count(), 'DNS zone', 'DNS zones'],
            'mariadb' => [TenantDatabase::query()->where('node_id', $node->id)->count(), 'database', 'databases'],
            'cron' => [CronJob::query()->where('node_id', $node->id)->count(), 'cron job', 'cron jobs'],
            'mail' => [MailDomain::query()->where('node_id', $node->id)->count(), 'mail domain', 'mail domains'],
            'backups' => [AccountBackup::query()->where('node_id', $node->id)->count() + Backup::query()->where('node_id', $node->id)->count(), 'backup', 'backups'],
            default => [0, '', ''],
        };

        [$n, $one, $many] = $count;

        if ($n === 0) {
            return null;
        }

        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }
}
