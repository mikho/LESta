<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Nodes\SuspendNode;
use App\Actions\Nodes\UnsuspendNode;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\NodeResource;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Nodes, for watching them and taking one out of service. Enrolling, changing and removing nodes,
 * their capabilities and services stay in the panel.
 */
class NodeController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Node::class);

        return NodeResource::collection(Node::query()->with('capabilities')->orderBy('id')->cursorPaginate($this->limit($request)));
    }

    public function show(Node $node): NodeResource
    {
        Gate::authorize('view', $node);

        return new NodeResource($node->load('capabilities'));
    }

    public function suspend(Request $request, Node $node): JsonResponse
    {
        app(SuspendNode::class)->handle($request->user(), $node);

        return $this->accepted($request, $node, new NodeResource($node->refresh()->load('capabilities')));
    }

    public function unsuspend(Request $request, Node $node): JsonResponse
    {
        app(UnsuspendNode::class)->handle($request->user(), $node);

        return $this->accepted($request, $node, new NodeResource($node->refresh()->load('capabilities')));
    }
}
