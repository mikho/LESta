<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\AccountBackups\ResolvesAccountBackupScope;
use App\Concerns\ResolvesCurrentAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\Node;
use App\Models\PackageLimit;
use App\Models\UsageSnapshot;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ResolvesCurrentAccount;

    /**
     * Show the current user's own dashboard: a real resource overview for a tenant with at least
     * one account membership, or an empty/welcome state for anyone else (a pure platform admin,
     * or a brand-new admin-created user not yet assigned an account) -- resolveAccount()'s
     * firstOrFail() would otherwise 500 for that second case.
     */
    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        // Counted once, for both the summary and the package limits.
        $account?->loadCount(['webDomains', 'dnsZones', 'mailDomains', 'tenantDatabases', 'cronJobs']);

        return Inertia::render('dashboard', [
            'account' => $account === null ? null : $this->presentAccount($account),
            'overview' => $account === null ? null : $this->presentOverview($account, $request->user()),
            'usage' => $account === null ? null : $this->presentUsage($account),
            'health' => $account === null ? null : $this->presentHealth($account),
            'nodeStatus' => $this->presentNodeStatus($request->user()),
        ]);
    }

    /**
     * Whether the account's hosting is in order: every resource whose latest change the server
     * could not apply, with the reason it gave and the page to fix it on, and how many changes are
     * still being applied. The dashboard says "all up to date" only when both are empty.
     *
     * @return array{attention: list<array{kind: string, name: string, url: string, status: string, error: string|null}>, applying: int}
     */
    private function presentHealth(Account $account): array
    {
        $sources = [
            ['Website', $account->webDomains(), 'domain', 'domains.edit'],
            ['DNS zone', $account->dnsZones(), 'domain', 'dns.edit'],
            ['Mail domain', $account->mailDomains(), 'domain', 'mail.edit'],
            ['Database', $account->tenantDatabases(), 'label', 'tenant-databases.edit'],
            ['Cron job', $account->cronJobs(), 'command', 'cron-jobs.edit'],
        ];

        $attention = [];
        $applying = 0;

        // Only the resources whose latest change is still running or went wrong are read; the rest
        // are not loaded at all.
        foreach ($sources as [$kind, $query, $nameColumn, $route]) {
            $applying += (clone $query)
                ->whereHas('latestProvisioningOperation', fn ($operation) => $operation->whereIn('status', ['pending', 'dispatched']))
                ->count();

            $needsAttention = $query
                ->whereHas('latestProvisioningOperation', fn ($operation) => $operation->whereIn('status', ['failed', 'rejected', 'degraded']))
                ->with('latestProvisioningOperation')
                ->get();

            foreach ($needsAttention as $resource) {
                $operation = $resource->latestProvisioningOperation;
                $message = $operation->errors[0]['message'] ?? null;
                $attention[] = [
                    'kind' => $kind,
                    'name' => (string) $resource->getAttribute($nameColumn),
                    'url' => route($route, $resource),
                    'status' => $operation->status->value,
                    'error' => is_string($message) ? $message : null,
                ];
            }
        }

        return ['attention' => $attention, 'applying' => $applying];
    }

    /** Seconds after the last heartbeat a node still counts as online (three missed beats). */
    private const int ONLINE_SECONDS = 180;

    /**
     * The status of the nodes the user administers: every node for a provider admin, the granted
     * ones for a node admin, null for everyone else. Heartbeat age, the control plane's ping, and the
     * systemd units the node last reported.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function presentNodeStatus(User $user): ?array
    {
        // nodes.view_any, not the policy's viewAny: that also passes for a node admin, who sees only
        // their granted nodes below.
        if ($user->hasPermission('nodes.view_any')) {
            $nodes = Node::query()->with('capabilities')->orderBy('name')->get();
        } elseif ($user->nodeAdminGrants()->exists()) {
            $nodes = Node::query()->with('capabilities')->whereIn('id', $user->nodeAdminGrants()->pluck('node_id'))->orderBy('name')->get();
        } else {
            return null;
        }

        return $nodes->map(fn (Node $node): array => [
            'id' => $node->id,
            'uuid' => $node->uuid,
            'name' => $node->name,
            'hostname' => $node->hostname,
            'suspended' => $node->isSuspended(),
            'agent_version' => $node->agent_version,
            'heartbeat' => [
                'online' => $node->last_seen_at !== null && $node->last_seen_at->diffInSeconds(now()) <= self::ONLINE_SECONDS,
                'last_seen_at' => $node->last_seen_at?->toIso8601String(),
            ],
            'ping' => $node->pinged_at === null ? null : [
                'ok' => (bool) $node->ping_ok,
                'ms' => $node->ping_ms,
                'at' => $node->pinged_at->toIso8601String(),
            ],
            'services' => $node->service_states ?? [],
            'capabilities' => $node->capabilities
                ->filter(fn ($capability): bool => $capability->status->value !== 'not_installed')
                ->map(fn ($capability): array => ['capability' => $capability->capability, 'status' => $capability->status->value])
                ->values()
                ->all(),
        ])->all();
    }

    /**
     * Who and where: plan, role, the primary domain, the servers the account is on, and the
     * user's previous sign-in.
     *
     * @return array<string, mixed>
     */
    private function presentOverview(Account $account, User $user): array
    {
        $primary = $account->webDomains()->with('ipAllocation')->orderBy('id')->first();

        return [
            'package' => $account->package->name,
            'role' => $user->hasAccountRole($account, 'owner') ? 'owner' : 'member',
            'contact_email' => $account->contact_email,
            'primary_domain' => $primary === null ? null : [
                'domain' => $primary->domain,
                'uuid' => $primary->uuid,
                'ssl_mode' => $primary->ssl_mode->value,
                'certificate_issued' => $primary->certificate_issued_at !== null,
                'ip_address' => $primary->ipAllocation?->ip_address,
                'suspended' => $primary->isSuspended(),
            ],
            'servers' => array_map(fn (Node $node): array => ['name' => $node->name, 'hostname' => $node->hostname], app(ResolvesAccountBackupScope::class)->nodesWithData($account)),
            'previous_sign_in' => $user->previous_login_at === null ? null : [
                'at' => $user->previous_login_at->toIso8601String(),
                'ip' => $user->previous_login_ip,
            ],
        ];
    }

    /**
     * What the account has used against what its package allows, and the traffic and disk the
     * nodes have reported. Limits come from the package: a null limit is unlimited, a missing one
     * means the plan does not include the resource.
     *
     * @return array<string, mixed>
     */
    private function presentUsage(Account $account): array
    {
        $limits = PackageLimit::query()->where('package_id', $account->package_id)->pluck('limit_value', 'resource_type');

        $resources = [
            ['key' => 'web_domains', 'label' => 'Web domains', 'used' => $account->web_domains_count],
            ['key' => 'dns_zones', 'label' => 'DNS zones', 'used' => $account->dns_zones_count],
            ['key' => 'mail_domains', 'label' => 'Mail domains', 'used' => $account->mail_domains_count],
            ['key' => 'tenant_databases', 'label' => 'Databases', 'used' => $account->tenant_databases_count],
            ['key' => 'cron_jobs', 'label' => 'Cron jobs', 'used' => $account->cron_jobs_count],
        ];

        $latestDisk = UsageSnapshot::query()
            ->where('account_id', $account->id)
            ->whereNotNull('disk_bytes')
            ->whereNotExists(fn ($newer) => $newer->selectRaw('1')
                ->from('usage_snapshots as newer')
                ->whereColumn('newer.snapshotable_type', 'usage_snapshots.snapshotable_type')
                ->whereColumn('newer.snapshotable_id', 'usage_snapshots.snapshotable_id')
                ->whereNotNull('newer.disk_bytes')
                ->whereColumn('newer.collected_at', '>', 'usage_snapshots.collected_at'));

        $recent = UsageSnapshot::query()->where('account_id', $account->id)->where('collected_at', '>=', now()->subDays(30));

        $collectedAt = UsageSnapshot::query()->where('account_id', $account->id)->max('collected_at');

        return [
            'limits' => array_map(fn (array $resource): array => $resource + [
                'included' => $limits->has($resource['key']),
                'limit' => $limits->get($resource['key']),
            ], $resources),
            'disk_bytes' => (int) (clone $latestDisk)->sum('disk_bytes'),
            'bandwidth_bytes_30d' => (int) (clone $recent)->sum('bytes_sent'),
            'requests_30d' => (int) (clone $recent)->sum('request_count'),
            'collected_at' => $collectedAt === null ? null : Carbon::parse($collectedAt)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAccount(Account $account): array
    {
        return [
            'name' => $account->name,
            'suspended_at' => $account->suspended_at?->toIso8601String(),
            'web_domains_count' => $account->web_domains_count,
            'dns_zones_count' => $account->dns_zones_count,
            'mail_domains_count' => $account->mail_domains_count,
            'mail_accounts_count' => MailAccount::query()
                ->whereIn('mail_domain_id', $account->mailDomains()->select('id'))
                ->count(),
            'tenant_databases_count' => $account->tenant_databases_count,
            'cron_jobs_count' => $account->cron_jobs_count,
        ];
    }
}
