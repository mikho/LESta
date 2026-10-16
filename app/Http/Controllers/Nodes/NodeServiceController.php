<?php

namespace App\Http\Controllers\Nodes;

use App\Actions\Nodes\ControlNodeService;
use App\Actions\Nodes\UninstallNodeService;
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

    /**
     * Remove one LESta service from the node. The node page asks for the node's name first.
     */
    public function destroy(Request $request, Node $node, string $service): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'string', 'in:'.$node->name]], ['confirm.in' => __('Type the node name exactly to confirm.')]);

        app(UninstallNodeService::class)->handle($request->user(), $node, $service);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removing :service from :node. This can take a few minutes.', ['service' => $service, 'node' => $node->name])]);

        return back();
    }
}
