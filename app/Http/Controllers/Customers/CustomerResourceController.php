<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The provider admin's read-only detail page for one customer resource, reached from the
 * cross-account lists. It exists to answer "what is this and why did it fail" without leaving the
 * admin view: operational facts, the resource's own sub-records, and its recent provisioning
 * operations with the reasons nodes reported. It deliberately shows no credentials (database and
 * mailbox passwords, keys), no operation payloads (they carry secrets), and no cron output or
 * mailbox contents. Gated by the same *.view_any permission as the list it is linked from, and
 * never offers an action: acting for a customer stays on the account page.
 */
class CustomerResourceController extends Controller
{
    private const array TYPES = [
        'domains' => [WebDomain::class, 'Domains', 'domains.index'],
        'dns' => [DnsZone::class, 'DNS', 'dns.index'],
        'mail' => [MailDomain::class, 'Mail', 'mail.index'],
        'databases' => [TenantDatabase::class, 'Databases', 'tenant-databases.index'],
        'cron-jobs' => [CronJob::class, 'Cron jobs', 'cron-jobs.index'],
    ];

    public function show(string $type, string $uuid): Response
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);

        [$class, $listLabel, $listRoute] = self::TYPES[$type];

        Gate::authorize('viewAnyAcrossAccounts', $class);

        [$resource, $present] = match ($type) {
            'domains' => $this->presenting(WebDomain::class, $uuid, fn (WebDomain $r): array => $this->webDomain($r)),
            'dns' => $this->presenting(DnsZone::class, $uuid, fn (DnsZone $r): array => $this->dnsZone($r)),
            'mail' => $this->presenting(MailDomain::class, $uuid, fn (MailDomain $r): array => $this->mailDomain($r)),
            'databases' => $this->presenting(TenantDatabase::class, $uuid, fn (TenantDatabase $r): array => $this->tenantDatabase($r)),
            'cron-jobs' => $this->presenting(CronJob::class, $uuid, fn (CronJob $r): array => $this->cronJob($r)),
        };

        return Inertia::render('customer-resources/show', [
            'resource' => [
                'type_label' => $listLabel,
                'list_url' => route($listRoute),
                'title' => $present['title'],
                'account' => ['public_id' => $resource->account->public_id, 'name' => $resource->account->name],
                'node' => $resource->node->name,
                'suspended' => $resource->suspended_at === null ? null : [
                    'at' => $resource->suspended_at->toIso8601String(),
                    'source' => $resource->suspension_source?->value,
                ],
                'facts' => array_merge([
                    $this->fact('Account', $resource->account->name),
                    $this->fact('Node', $resource->node->name),
                    $this->fact('Created', $resource->created_at->toIso8601String(), 'datetime'),
                ], $present['facts']),
                'tables' => $present['tables'],
                'operations' => $this->operations($resource),
            ],
        ]);
    }

    /**
     * Loads one resource by uuid, with the relations every detail page shows, and presents it.
     *
     * @template TResource of Model
     *
     * @param  class-string<TResource>  $class
     * @param  Closure(TResource): array{title: string, facts: list<array<string, string>>, tables: list<array<string, mixed>>}  $present
     * @return array{0: TResource, 1: array{title: string, facts: list<array<string, string>>, tables: list<array<string, mixed>>}}
     */
    private function presenting(string $class, string $uuid, Closure $present): array
    {
        /** @var TResource $resource */
        $resource = $class::query()->where('uuid', $uuid)->with(['account:id,public_id,name', 'node:id,uuid,name'])->firstOrFail();

        return [$resource, $present($resource)];
    }

    /**
     * @return array{title: string, facts: list<array<string, string>>, tables: list<array<string, mixed>>}
     */
    private function webDomain(WebDomain $domain): array
    {
        return [
            'title' => $domain->domain,
            'facts' => [
                $this->fact('Web server', $domain->web_server->value),
                $this->fact('Template', $domain->web_template),
                $this->fact('PHP version', $domain->php_version->value ?? 'None (static only)'),
                $this->fact('SSL mode', $domain->ssl_mode->value),
                $this->fact('Firewall (WAF)', $domain->waf_mode),
                $this->fact('WAF preset', $domain->waf_preset),
                $this->fact('Hotlink protection', $domain->hotlink_protection ? 'On' : 'Off'),
                $this->fact('Excluded WAF rules', implode(', ', $domain->waf_excluded_rules ?? []) ?: 'None'),
                $this->fact('Certificate issued', $domain->certificate_issued_at?->toIso8601String() ?? 'Not yet', $domain->certificate_issued_at ? 'datetime' : 'text'),
                $this->fact('Certificate expires', $domain->certificate_expires_at?->toIso8601String() ?? 'Not recorded', $domain->certificate_expires_at ? 'datetime' : 'text'),
                $this->fact('Last certificate error', $domain->last_certificate_error ?? 'None'),
                $this->fact('Aliases', $domain->aliases()->pluck('alias')->implode(', ') ?: 'None'),
            ],
            'tables' => [],
        ];
    }

    /**
     * @return array{title: string, facts: list<array<string, string>>, tables: list<array<string, mixed>>}
     */
    private function dnsZone(DnsZone $zone): array
    {
        $records = $zone->records()->orderBy('type')->orderBy('name')->limit(200)->get();

        return [
            'title' => $zone->domain,
            'facts' => [
                $this->fact('Default TTL', (string) $zone->ttl),
                $this->fact('Records', (string) $zone->records()->count()),
            ],
            'tables' => [[
                'title' => 'Records',
                'columns' => ['Type', 'Name', 'Priority', 'Value', 'Status'],
                'rows' => $records->map(fn ($record): array => [
                    $record->type->value,
                    $record->name,
                    $record->priority === null ? '' : (string) $record->priority,
                    $record->value,
                    $record->suspended_at === null ? 'Active' : 'Suspended',
                ])->all(),
            ]],
        ];
    }

    /**
     * @return array{title: string, facts: list<array<string, string>>, tables: list<array<string, mixed>>}
     */
    private function mailDomain(MailDomain $domain): array
    {
        $mailboxes = $domain->accounts()->orderBy('local_part')->get();

        return [
            'title' => $domain->domain,
            'facts' => [
                $this->fact('Antivirus', $domain->antivirus_enabled ? 'On' : 'Off'),
                $this->fact('Antispam', $domain->antispam_enabled ? 'On' : 'Off'),
                $this->fact('DKIM', $domain->dkim_enabled ? 'On' : 'Off'),
                $this->fact('DKIM selector', $domain->dkim_selector ?? 'None'),
                $this->fact('Catch-all', $domain->catchall_email ?? 'None'),
                $this->fact('Mailboxes', (string) $mailboxes->count()),
                $this->fact('Mailing lists', (string) $domain->mailingLists()->count()),
            ],
            'tables' => [[
                'title' => 'Mailboxes',
                'columns' => ['Address', 'Quota', 'Forwards', 'Autoreply', 'Status'],
                'rows' => $mailboxes->map(fn ($mailbox): array => [
                    $mailbox->local_part.'@'.$domain->domain,
                    $mailbox->quota_mb === null ? 'Unlimited' : $mailbox->quota_mb.' MB',
                    $mailbox->forward_to === null ? 'No' : 'Yes',
                    $mailbox->autoreply_enabled ? 'On' : 'Off',
                    $mailbox->suspended_at === null ? 'Active' : 'Suspended',
                ])->all(),
            ]],
        ];
    }

    /**
     * @return array{title: string, facts: list<array<string, string>>, tables: list<array<string, mixed>>}
     */
    private function tenantDatabase(TenantDatabase $database): array
    {
        return [
            'title' => $database->label,
            'facts' => [
                $this->fact('Database', $database->database_name),
                $this->fact('Database user', $database->database_user),
            ],
            'tables' => [],
        ];
    }

    /**
     * @return array{title: string, facts: list<array<string, string>>, tables: list<array<string, mixed>>}
     */
    private function cronJob(CronJob $job): array
    {
        $executions = $job->executions()->latest('started_at')->limit(20)->get();

        return [
            'title' => $job->command,
            'facts' => [
                $this->fact('Schedule', implode(' ', [$job->minute, $job->hour, $job->day_of_month, $job->month, $job->day_of_week]), 'code'),
                $this->fact('Command', $job->command, 'code'),
            ],
            'tables' => [[
                'title' => 'Recent runs (output is not shown)',
                'columns' => ['Started', 'Finished', 'Exit code'],
                'rows' => $executions->map(fn ($run): array => [
                    $run->started_at->toIso8601String(),
                    $run->finished_at->toIso8601String(),
                    (string) $run->exit_code,
                ])->all(),
            ]],
        ];
    }

    /**
     * The resource's most recent provisioning operations, newest first. Payloads are never
     * included: they carry credentials on the way to a node.
     *
     * @return array<int, array<string, mixed>>
     */
    private function operations(Model $resource): array
    {
        return ProvisioningOperation::query()
            ->where('provisionable_type', $resource->getMorphClass())
            ->where('provisionable_id', $resource->getKey())
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (ProvisioningOperation $operation): array => [
                'at' => $operation->created_at->toIso8601String(),
                'capability' => $operation->capability,
                'operation' => $operation->operation->value,
                'status' => $operation->status->value,
                'error' => is_string($message = $operation->errors[0]['message'] ?? null) ? $message : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{label: string, value: string, kind: string}
     */
    private function fact(string $label, string $value, string $kind = 'text'): array
    {
        return ['label' => $label, 'value' => $value, 'kind' => $kind];
    }
}
