<?php

namespace App\Http\Controllers\Nodes;

use App\Actions\Cron\DeleteOrphanedAccountNodeIdentity;
use App\Actions\Nodes\ConfirmNodeToolsCertificate;
use App\Actions\Nodes\CreateNode;
use App\Actions\Nodes\DeleteNode;
use App\Actions\Nodes\EnsureNodeToolsDomain;
use App\Actions\Nodes\GrantNodeAdmin;
use App\Actions\Nodes\IssueNodeEnrollmentToken;
use App\Actions\Nodes\ResolvesNodeServiceRemoval;
use App\Actions\Nodes\RevokeNodeAdminGrant;
use App\Actions\Nodes\SuspendNode;
use App\Actions\Nodes\UnsuspendNode;
use App\Actions\Nodes\UpdateNode;
use App\Actions\Nodes\UpdateNodeScheduledBackups;
use App\Enums\NodeCapabilityType;
use App\Enums\ProvisioningStatus;
use App\Exceptions\NoIpAllocationAvailableException;
use App\Exceptions\NoWebCapableNodeAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nodes\GrantNodeAdminRequest;
use App\Http\Requests\Nodes\StoreNodeRequest;
use App\Http\Requests\Nodes\StoreNodeToolsDomainRequest;
use App\Http\Requests\Nodes\UpdateNodeRequest;
use App\Models\AccountNodeIdentity;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Node;
use App\Models\NodeAdminGrant;
use App\Models\NodeCapability;
use App\Models\NodeServiceAction;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NodeController extends Controller
{
    /**
     * Show the platform node list.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Node::class);

        $search = trim((string) $request->string('search'));
        $user = $request->user();

        $nodes = Node::query()
            ->withCount('capabilities')
            // A node-scoped admin (no platform nodes.view_any permission) sees only the node(s)
            // they were actually granted, never the full platform fleet.
            ->when(
                ! $user->hasPermission('nodes.view_any'),
                fn ($query) => $query->whereIn('id', $user->nodeAdminGrants()->pluck('node_id'))
            )
            ->when(
                $search !== '',
                fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('hostname', 'like', '%'.$search.'%')
            )
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $nodes->through(fn (Node $node): array => $this->presentForIndex($node));

        return Inertia::render('nodes/index', [
            'nodes' => $nodes,
            'search' => $search,
        ]);
    }

    /**
     * Show the form for creating a new node.
     */
    public function create(): Response
    {
        Gate::authorize('create', Node::class);

        return Inertia::render('nodes/create');
    }

    /**
     * Store a newly created node.
     */
    public function store(StoreNodeRequest $request): RedirectResponse
    {
        $node = app(CreateNode::class)->handle($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Node created.')]);

        return to_route('nodes.edit', $node);
    }

    /**
     * Show the form for editing a node.
     */
    public function edit(Request $request, Node $node): Response
    {
        Gate::authorize('update', $node);

        $node->load([
            'capabilities',
            'provisioningOperations' => fn ($query) => $query->latest('issued_at')->limit(20),
            'adminGrants.user',
        ]);

        $orphanedIdentities = AccountNodeIdentity::query()
            ->orphanedOn($node)
            ->with('account')
            ->get();

        return Inertia::render('nodes/edit', [
            'node' => $this->presentForEdit($node, $orphanedIdentities),
            'canManageAdminGrants' => $request->user()->hasPermission('nodes.update'),
        ]);
    }

    /**
     * Create the web domain for this node's own hostname (owned by the hidden platform account),
     * which hosts webmail and Adminer.
     */
    public function createToolsDomain(StoreNodeToolsDomainRequest $request, Node $node): RedirectResponse
    {
        Gate::authorize('update', $node);

        try {
            app(EnsureNodeToolsDomain::class)->handle($node, $request->validated('ssl_mode'), $request->user());
        } catch (NoWebCapableNodeAvailableException|NoIpAllocationAvailableException $exception) {
            throw ValidationException::withMessages([
                'tools_domain' => 'This node cannot host the domain yet: it needs nginx declared and an IP allocation.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Web domain for the node hostname created.')]);

        return to_route('nodes.edit', $node);
    }

    /**
     * Record that the manually installed certificate for the node hostname is in place.
     */
    public function confirmToolsCertificate(Request $request, Node $node): RedirectResponse
    {
        app(ConfirmNodeToolsCertificate::class)->handle($request->user(), $node);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Certificate recorded. The web server is being updated.')]);

        return to_route('nodes.edit', $node);
    }

    /**
     * Delegate full admin of the given node to the user identified by the submitted email.
     */
    public function grantAdmin(GrantNodeAdminRequest $request, Node $node): RedirectResponse
    {
        app(GrantNodeAdmin::class)->handle($request->user(), $node, $request->validated('email'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Node admin access granted.')]);

        return to_route('nodes.edit', $node);
    }

    /**
     * Revoke a previously delegated node admin grant.
     */
    public function revokeAdminGrant(Request $request, Node $node, NodeAdminGrant $grant): RedirectResponse
    {
        app(RevokeNodeAdminGrant::class)->handle($request->user(), $grant);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Node admin access revoked.')]);

        return to_route('nodes.edit', $node);
    }

    /**
     * Delete an orphaned account-node identity on the given node.
     */
    public function destroyOrphanedIdentity(Request $request, Node $node, AccountNodeIdentity $accountNodeIdentity): RedirectResponse
    {
        app(DeleteOrphanedAccountNodeIdentity::class)->handle($request->user(), $accountNodeIdentity);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Identity deleted.')]);

        return to_route('nodes.edit', $node);
    }

    /**
     * Update the given node.
     */
    public function update(UpdateNodeRequest $request, Node $node): RedirectResponse
    {
        app(UpdateNode::class)->handle($request->user(), $node, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Node updated.')]);

        return to_route('nodes.edit', $node);
    }

    /**
     * Suspend the given node.
     */
    public function suspend(Request $request, Node $node): RedirectResponse
    {
        app(SuspendNode::class)->handle($request->user(), $node);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Node suspended.')]);

        return back();
    }

    /**
     * Unsuspend the given node.
     */
    public function unsuspend(Request $request, Node $node): RedirectResponse
    {
        app(UnsuspendNode::class)->handle($request->user(), $node);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Node unsuspended.')]);

        return back();
    }

    /**
     * Turn on scheduled backups for the given node.
     */
    public function enableScheduledBackups(Request $request, Node $node): RedirectResponse
    {
        app(UpdateNodeScheduledBackups::class)->handle($request->user(), $node, true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Scheduled backups enabled.')]);

        return back();
    }

    /**
     * Turn off scheduled backups for the given node.
     */
    public function disableScheduledBackups(Request $request, Node $node): RedirectResponse
    {
        app(UpdateNodeScheduledBackups::class)->handle($request->user(), $node, false);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Scheduled backups disabled.')]);

        return back();
    }

    /**
     * Delete the given node. DeleteNode throws a ValidationException when the node still has
     * dependent resources, which Laravel's own exception handling turns into the standard
     * Inertia error-bag response the edit page's delete dialog renders.
     */
    public function destroy(Request $request, Node $node): RedirectResponse
    {
        app(DeleteNode::class)->handle($request->user(), $node);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Node deleted.')]);

        return to_route('nodes.index');
    }

    /**
     * Issue a fresh enrollment token for the given node. The raw, one-time token rides back on a
     * dedicated flash key rather than a page prop, so it never resurfaces on a later GET or
     * back-navigation.
     */
    public function issueEnrollmentToken(Request $request, Node $node): RedirectResponse
    {
        $token = app(IssueNodeEnrollmentToken::class)->handle($request->user(), $node);

        Inertia::flash('enrollmentToken', $token);

        return back();
    }

    /**
     * Shape a node for the index listing. No App\Http\Resources in this app; inline shaping
     * matches the existing precedent (DnsZoneController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(Node $node): array
    {
        return [
            'uuid' => $node->uuid,
            'name' => $node->name,
            'hostname' => $node->hostname,
            'enrollment_status' => $node->enrollment_status->value,
            'last_seen_at' => $node->last_seen_at?->toIso8601String(),
            'suspended_at' => $node->suspended_at?->toIso8601String(),
            'suspension_source' => $node->suspension_source?->value,
            'capabilities_count' => $node->capabilities_count,
        ];
    }

    /**
     * The state of this node's tools address: the web domain for its own hostname, and whether
     * webmail and Adminer can use it.
     *
     * @return array<string, mixed>
     */
    private function presentTools(Node $node): array
    {
        $domain = WebDomain::query()
            ->where('node_id', $node->id)
            ->where('domain', WebDomain::normalizeDomain($node->hostname))
            ->first();

        return [
            'hostname' => $node->hostname,
            'domain' => $domain === null ? null : [
                'uuid' => $domain->uuid,
                'ssl_mode' => $domain->ssl_mode->value,
                'certificate_issued_at' => $domain->certificate_issued_at?->toIso8601String(),
                'certificate_expires_at' => $domain->certificate_expires_at?->toIso8601String(),
                'last_certificate_error' => $domain->last_certificate_error,
                'certificate_path' => '/var/lib/lesta/acme/certs/'.$domain->domain,
            ],
            'webmail' => ['declared' => $node->hasCapability(NodeCapabilityType::Webmail), 'available' => $node->hasWebmailAvailable()],
            'adminer' => ['declared' => $node->hasCapability(NodeCapabilityType::Adminer), 'available' => $node->hasAdminerAvailable()],
        ];
    }

    /**
     * Shape a node, with its capabilities, recent provisioning operations, and orphaned
     * account-node identities, for the edit page.
     *
     * @param  Collection<int, AccountNodeIdentity>  $orphanedIdentities
     * @return array<string, mixed>
     */
    private function presentForEdit(Node $node, Collection $orphanedIdentities): array
    {
        return [
            'uuid' => $node->uuid,
            'name' => $node->name,
            'hostname' => $node->hostname,
            'mail_hostname' => $node->mail_hostname,
            'enrollment_status' => $node->enrollment_status->value,
            'protocol_version' => $node->protocol_version,
            'agent_version' => $node->agent_version,
            'last_seen_at' => $node->last_seen_at?->toIso8601String(),
            'agent_reachable' => $node->isAgentReachable(),
            'suspended_at' => $node->suspended_at?->toIso8601String(),
            'suspension_source' => $node->suspension_source?->value,
            'backups_scheduled' => $node->backups_scheduled,
            'tools' => $this->presentTools($node),
            'services' => $this->presentServices($node),
            'installed_services' => $this->presentInstalledServices($node),
            'capabilities' => $node->capabilities
                ->map(fn (NodeCapability $capability): array => $this->presentCapability($node, $capability))
                ->all(),
            'recent_operations' => $node->provisioningOperations
                ->map(fn (ProvisioningOperation $operation): array => $this->presentOperation($operation))
                ->all(),
            'orphaned_identities' => $orphanedIdentities
                ->map(fn (AccountNodeIdentity $identity): array => $this->presentOrphanedIdentity($identity))
                ->all(),
            'admin_grants' => $node->adminGrants
                ->map(fn (NodeAdminGrant $grant): array => $this->presentAdminGrant($grant))
                ->all(),
        ];
    }

    /**
     * The services the node last reported, whether each can be controlled from here, a start, stop
     * or restart still on its way, and how many customer resources on this node depend on it (shown
     * before a stop).
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentServices(Node $node): array
    {
        $pending = NodeServiceAction::query()
            ->where('node_id', $node->id)
            ->where('action', '!=', 'uninstall')
            ->whereHas('latestProvisioningOperation', fn ($query) => $query->whereIn('status', [ProvisioningStatus::Pending, ProvisioningStatus::Dispatched]))
            ->get(['unit', 'action'])
            ->keyBy('unit');

        return collect($node->service_states ?? [])->map(fn (array $service): array => [
            'name' => $service['name'],
            'state' => $service['state'],
            'controllable' => NodeServiceAction::isControllable($service['name']),
            'pending_action' => $pending->get($service['name'])?->action,
            'dependents' => $this->serviceDependents($node, $service['name']),
        ])->values()->all();
    }

    /**
     * The LESta services on the node that the panel may remove, what blocks removing each, a removal
     * on its way, and the error of the last one that failed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentInstalledServices(Node $node): array
    {
        $removal = app(ResolvesNodeServiceRemoval::class);

        $latest = NodeServiceAction::query()
            ->where('node_id', $node->id)
            ->where('action', 'uninstall')
            ->with('latestProvisioningOperation')
            ->latest('id')
            ->get()
            ->unique('unit')
            ->keyBy('unit');

        return array_map(function (string $service) use ($node, $removal, $latest): array {
            $operation = $latest->get($service)?->latestProvisioningOperation;
            $status = $operation?->status;

            return [
                'name' => $service,
                'stateful' => in_array($service, ResolvesNodeServiceRemoval::STATEFUL, true),
                'blockers' => $removal->blockers($node, $service),
                'removing' => in_array($status, [ProvisioningStatus::Pending, ProvisioningStatus::Dispatched], true),
                'last_error' => in_array($status, [ProvisioningStatus::Failed, ProvisioningStatus::Rejected], true)
                    ? collect($operation->errors ?? [])->pluck('message')->implode(' ')
                    : null,
            ];
        }, $removal->installed($node));
    }

    /**
     * What on this node stops working while $unit is down, as a short sentence, or null.
     */
    private function serviceDependents(Node $node, string $unit): ?string
    {
        $count = match (true) {
            $unit === 'nginx', $unit === 'apache2' => [WebDomain::query()->where('node_id', $node->id)->count(), 'web domain', 'web domains'],
            (bool) preg_match('/^php([0-9.]+)-fpm$/', $unit, $m) => [WebDomain::query()->where('node_id', $node->id)->where('php_version', $m[1])->count(), 'web domain using PHP '.$m[1], 'web domains using PHP '.$m[1]],
            $unit === 'named' => [DnsZone::query()->where('node_id', $node->id)->count(), 'DNS zone', 'DNS zones'],
            $unit === 'mariadb@tenant' => [TenantDatabase::query()->where('node_id', $node->id)->count(), 'database', 'databases'],
            $unit === 'cron' => [CronJob::query()->where('node_id', $node->id)->count(), 'cron job', 'cron jobs'],
            $unit === 'exim4', $unit === 'dovecot' => [MailDomain::query()->where('node_id', $node->id)->count(), 'mail domain', 'mail domains'],
            default => null,
        };

        if ($count === null) {
            return null;
        }

        [$n, $one, $many] = $count;

        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }

    /**
     * Shape a node admin grant for the frontend.
     *
     * @return array<string, mixed>
     */
    private function presentAdminGrant(NodeAdminGrant $grant): array
    {
        return [
            'uuid' => $grant->uuid,
            'user_name' => $grant->user->name,
            'user_email' => $grant->user->email,
            'created_at' => $grant->created_at?->toIso8601String(),
        ];
    }

    /**
     * Shape an orphaned account-node identity for the frontend.
     *
     * @return array<string, mixed>
     */
    private function presentOrphanedIdentity(AccountNodeIdentity $identity): array
    {
        return [
            'uuid' => $identity->uuid,
            'system_username' => $identity->system_username,
            'account_id' => $identity->account_id,
            'account_name' => $identity->account?->name,
            'created_at' => $identity->created_at?->toIso8601String(),
        ];
    }

    /**
     * Shape a node capability for the frontend. Takes the owning Node explicitly (rather than
     * relying on $capability->node lazy-loading a possibly-different instance) since it's already
     * in scope in presentForEdit() and is needed here to compute agent reachability.
     *
     * @return array<string, mixed>
     */
    private function presentCapability(Node $node, NodeCapability $capability): array
    {
        $type = NodeCapabilityType::tryFrom($capability->capability);
        $reachable = $node->isAgentReachable();

        return [
            'id' => $capability->id,
            'capability' => $capability->capability,
            'status' => $capability->status->value,
            'supports_status_tracking' => $type?->supportsStatusTracking() ?? true,
            'suspended_at' => $capability->suspended_at?->toIso8601String(),
            'suspension_source' => $capability->suspension_source?->value,
            'last_seen_at' => $capability->last_seen_at?->toIso8601String(),
            // Resolution order: an admin's own suspend action is authoritative and always wins,
            // even if the node is currently unreachable; otherwise, an unreachable agent collapses
            // the true underlying status to "unknown" rather than trusting a value we can no
            // longer verify. Computed here, once, so the frontend never re-derives this rule.
            'display_status' => match (true) {
                $capability->isSuspended() => 'suspended',
                ! $reachable => 'unknown',
                default => $capability->status->value,
            },
        ];
    }

    /**
     * Shape a provisioning operation for the frontend.
     *
     * @return array<string, mixed>
     */
    private function presentOperation(ProvisioningOperation $operation): array
    {
        return [
            'capability' => $operation->capability,
            'operation' => $operation->operation->value,
            'status' => $operation->status->value,
            'issued_at' => $operation->issued_at->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
        ];
    }
}
