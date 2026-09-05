<?php

namespace App\Http\Controllers\Nodes;

use App\Actions\Nodes\AddNodeCapability;
use App\Actions\Nodes\SuspendNodeCapability;
use App\Actions\Nodes\UnsuspendNodeCapability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nodes\StoreNodeCapabilityRequest;
use App\Models\Node;
use App\Models\NodeCapability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NodeCapabilityController extends Controller
{
    /**
     * Add a capability to the given node.
     */
    public function store(StoreNodeCapabilityRequest $request, Node $node): RedirectResponse
    {
        app(AddNodeCapability::class)->handle($request->user(), $node, $request->validated('capability'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Capability added.')]);

        return to_route('nodes.edit', $node);
    }

    /**
     * Suspend the given node capability.
     */
    public function suspend(Request $request, Node $node, NodeCapability $nodeCapability): RedirectResponse
    {
        app(SuspendNodeCapability::class)->handle($request->user(), $nodeCapability);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Capability suspended.')]);

        return to_route('nodes.edit', $node);
    }

    /**
     * Unsuspend the given node capability.
     */
    public function unsuspend(Request $request, Node $node, NodeCapability $nodeCapability): RedirectResponse
    {
        app(UnsuspendNodeCapability::class)->handle($request->user(), $nodeCapability);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Capability unsuspended.')]);

        return to_route('nodes.edit', $node);
    }
}
