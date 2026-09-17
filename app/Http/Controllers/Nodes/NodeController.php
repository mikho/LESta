<?php

namespace App\Http\Controllers\Nodes;

use App\Actions\Cron\DeleteOrphanedAccountNodeIdentity;
use App\Actions\Nodes\CreateNode;
use App\Actions\Nodes\DeleteNode;
use App\Actions\Nodes\GrantNodeAdmin;
use App\Actions\Nodes\IssueNodeEnrollmentToken;
use App\Actions\Nodes\RevokeNodeAdminGrant;
use App\Actions\Nodes\SuspendNode;
use App\Actions\Nodes\UnsuspendNode;
use App\Actions\Nodes\UpdateNode;
use App\Actions\Nodes\UpdateNodeScheduledBackups;
use App\Enums\NodeCapabilityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nodes\GrantNodeAdminRequest;
use App\Http\Requests\Nodes\StoreNodeRequest;
use App\Http\Requests\Nodes\UpdateNodeRequest;
use App\Models\AccountNodeIdentity;
use App\Models\Node;
use App\Models\NodeAdminGrant;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
            'enrollment_status' => $node->enrollment_status->value,
            'protocol_version' => $node->protocol_version,
            'agent_version' => $node->agent_version,
            'last_seen_at' => $node->last_seen_at?->toIso8601String(),
            'agent_reachable' => $node->isAgentReachable(),
            'suspended_at' => $node->suspended_at?->toIso8601String(),
            'suspension_source' => $node->suspension_source?->value,
            'backups_scheduled' => $node->backups_scheduled,
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
