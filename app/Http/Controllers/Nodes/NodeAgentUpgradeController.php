<?php

namespace App\Http\Controllers\Nodes;

use App\Actions\Nodes\ManageAgentUpgradeKeys;
use App\Actions\Nodes\UpgradeNodeAgent;
use App\Http\Controllers\Controller;
use App\Models\AgentUpgradeKey;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Upgrading a node's agent from the panel, and the public keys nodes trust for it. The signature is
 * made in the administrator's browser; nothing here ever receives a private key.
 */
class NodeAgentUpgradeController extends Controller
{
    /**
     * What the browser needs to build and sign the request.
     */
    public function show(Request $request, Node $node): JsonResponse
    {
        return response()->json(app(UpgradeNodeAgent::class)->describe($request->user(), $node));
    }

    public function store(Request $request, Node $node): RedirectResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:32'],
            'sha256' => ['required', 'string', 'size:64'],
            'expires_at' => ['required', 'integer'],
            'signature' => ['required', 'string', 'max:200'],
            'next_key' => ['nullable', 'string', 'uuid'],
        ]);

        app(UpgradeNodeAgent::class)->handle($request->user(), $node, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Upgrading the agent on :node. The version updates when it reports back.', ['node' => $node->name])]);

        return back();
    }

    /**
     * Record which key this node trusts.
     */
    public function assignKey(Request $request, Node $node): RedirectResponse
    {
        $validated = $request->validate(['upgrade_key' => ['nullable', 'string', 'uuid']]);

        $key = ($validated['upgrade_key'] ?? null) === null ? null : AgentUpgradeKey::query()->where('uuid', $validated['upgrade_key'])->firstOrFail();

        app(ManageAgentUpgradeKeys::class)->assign($request->user(), $node, $key);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Upgrade key recorded.')]);

        return back();
    }

    public function storeKey(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'public_key' => ['required', 'string', 'max:64'],
        ]);

        app(ManageAgentUpgradeKeys::class)->create($request->user(), $validated['name'], $validated['public_key']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Upgrade key registered.')]);

        return back();
    }

    public function destroyKey(Request $request, AgentUpgradeKey $agentUpgradeKey): RedirectResponse
    {
        app(ManageAgentUpgradeKeys::class)->delete($request->user(), $agentUpgradeKey);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Upgrade key removed.')]);

        return back();
    }
}
