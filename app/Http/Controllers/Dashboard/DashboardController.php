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

        return Inertia::render('dashboard', [
            'account' => $account === null ? null : $this->presentAccount($account),
            'overview' => $account === null ? null : $this->presentOverview($account, $request->user()),
            'usage' => $account === null ? null : $this->presentUsage($account),
        ]);
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
            ['key' => 'web_domains', 'label' => 'Web domains', 'used' => $account->webDomains()->count()],
            ['key' => 'dns_zones', 'label' => 'DNS zones', 'used' => $account->dnsZones()->count()],
            ['key' => 'mail_domains', 'label' => 'Mail domains', 'used' => $account->mailDomains()->count()],
            ['key' => 'tenant_databases', 'label' => 'Databases', 'used' => $account->tenantDatabases()->count()],
            ['key' => 'cron_jobs', 'label' => 'Cron jobs', 'used' => $account->cronJobs()->count()],
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
            'web_domains_count' => $account->webDomains()->count(),
            'dns_zones_count' => $account->dnsZones()->count(),
            'mail_domains_count' => $account->mailDomains()->count(),
            'mail_accounts_count' => MailAccount::query()
                ->whereIn('mail_domain_id', $account->mailDomains()->pluck('id'))
                ->count(),
            'tenant_databases_count' => $account->tenantDatabases()->count(),
            'cron_jobs_count' => $account->cronJobs()->count(),
        ];
    }
}
