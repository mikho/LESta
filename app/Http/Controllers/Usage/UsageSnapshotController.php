<?php

namespace App\Http\Controllers\Usage;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\TenantDatabase;
use App\Models\UsageSnapshot;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UsageSnapshotController extends Controller
{
    /**
     * Show the current user's own account's usage history: real, raw, per-collection-cycle
     * snapshots (see App\Console\Commands\CollectUsageMetrics), newest first. There is no
     * aggregation into per-resource "latest only" or coarser-grained rollups yet -- a real,
     * disclosed v1 boundary (see UsageSnapshot's own doc comment), not an oversight.
     */
    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        Gate::authorize('viewAny', [UsageSnapshot::class, $account]);

        $snapshots = $account->usageSnapshots()
            ->with('snapshotable')
            ->orderByDesc('collected_at')
            ->paginate(20)
            ->withQueryString();

        $snapshots->through(fn (UsageSnapshot $snapshot): array => $this->presentForIndex($snapshot));

        return Inertia::render('usage/index', [
            'snapshots' => $snapshots,
        ]);
    }

    private function resolveAccount(User $user): Account
    {
        return $user->memberships()->whereNotNull('account_id')->with('account')->firstOrFail()->account;
    }

    /**
     * Shape a usage snapshot for the index listing, including a human-readable label for its
     * own polymorphic snapshotable (a MailAccount, TenantDatabase, or WebDomain) since the raw
     * morph type/id pair means nothing to a user. No App\Http\Resources in this app; inline
     * shaping matches the existing precedent (NodeController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(UsageSnapshot $snapshot): array
    {
        return [
            'uuid' => $snapshot->uuid,
            'resource_type' => $this->resourceType($snapshot),
            'resource_label' => $this->resourceLabel($snapshot),
            'disk_bytes' => $snapshot->disk_bytes,
            'request_count' => $snapshot->request_count,
            'bytes_sent' => $snapshot->bytes_sent,
            'collected_at' => $snapshot->collected_at->toIso8601String(),
        ];
    }

    private function resourceType(UsageSnapshot $snapshot): string
    {
        return match ($snapshot->snapshotable_type) {
            (new MailAccount)->getMorphClass() => 'mail_account',
            (new TenantDatabase)->getMorphClass() => 'tenant_database',
            (new WebDomain)->getMorphClass() => 'web_domain',
            default => 'unknown',
        };
    }

    private function resourceLabel(UsageSnapshot $snapshot): string
    {
        $resource = $snapshot->snapshotable;

        if ($resource === null) {
            return '(deleted resource)';
        }

        return match (true) {
            $resource instanceof MailAccount => $resource->local_part.'@'.$resource->mailDomain?->domain,
            $resource instanceof TenantDatabase => $resource->label ?? $resource->database_name,
            $resource instanceof WebDomain => $resource->domain,
            default => '(unknown resource)',
        };
    }
}
