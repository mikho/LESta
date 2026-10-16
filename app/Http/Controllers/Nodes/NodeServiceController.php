<?php

namespace App\Http\Controllers\Nodes;

use App\Actions\Nodes\ControlNodeService;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NodeServiceController extends Controller
{
    /**
     * Start, stop or restart one of the node's services.
     */
    public function store(Request $request, Node $node, string $unit, string $action): RedirectResponse
    {
        app(ControlNodeService::class)->handle($request->user(), $node, $unit, $action);

        $verb = ['start' => 'Starting', 'stop' => 'Stopping', 'restart' => 'Restarting'][$action];
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':verb :unit on :node. The state updates when the node reports back.', ['verb' => $verb, 'unit' => $unit, 'node' => $node->name])]);

        return back();
    }
}
