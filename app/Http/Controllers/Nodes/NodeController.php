<?php

namespace App\Http\Controllers\Nodes;

use App\Actions\Nodes\CreateNode;
use App\Actions\Nodes\DeleteNode;
use App\Actions\Nodes\IssueNodeEnrollmentToken;
use App\Actions\Nodes\SuspendNode;
use App\Actions\Nodes\UnsuspendNode;
use App\Actions\Nodes\UpdateNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nodes\StoreNodeRequest;
use App\Http\Requests\Nodes\UpdateNodeRequest;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
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

        $nodes = Node::query()
            ->withCount('capabilities')
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
        ]);

        return Inertia::render('nodes/edit', [
            'node' => $this->presentForEdit($node),
        ]);
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
     * Shape a node, with its capabilities and recent provisioning operations, for the edit page.
     *
     * @return array<string, mixed>
     */
    private function presentForEdit(Node $node): array
    {
        return [
            'uuid' => $node->uuid,
            'name' => $node->name,
            'hostname' => $node->hostname,
            'enrollment_status' => $node->enrollment_status->value,
            'protocol_version' => $node->protocol_version,
            'agent_version' => $node->agent_version,
            'last_seen_at' => $node->last_seen_at?->toIso8601String(),
            'suspended_at' => $node->suspended_at?->toIso8601String(),
            'suspension_source' => $node->suspension_source?->value,
            'capabilities' => $node->capabilities
                ->map(fn (NodeCapability $capability): array => $this->presentCapability($capability))
                ->all(),
            'recent_operations' => $node->provisioningOperations
                ->map(fn (ProvisioningOperation $operation): array => $this->presentOperation($operation))
                ->all(),
        ];
    }

    /**
     * Shape a node capability for the frontend.
     *
     * @return array<string, mixed>
     */
    private function presentCapability(NodeCapability $capability): array
    {
        return [
            'id' => $capability->id,
            'capability' => $capability->capability,
            'suspended_at' => $capability->suspended_at?->toIso8601String(),
            'suspension_source' => $capability->suspension_source?->value,
            'last_seen_at' => $capability->last_seen_at?->toIso8601String(),
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
