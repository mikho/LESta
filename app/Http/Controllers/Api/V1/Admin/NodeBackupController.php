<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Backups\CreateBackup;
use App\Actions\Backups\DeleteBackup;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\NodeBackupResource;
use App\Models\Backup;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Whole-node backups. Restoring a node and downloading its backup stay in the panel.
 */
class NodeBackupController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Backup::class);

        $request->validate(['filter.node' => ['sometimes', 'string']]);

        return NodeBackupResource::collection(Backup::query()
            ->with('node:id,uuid,name')
            ->when($request->filled('filter.node'), fn ($query) => $query->whereHas('node', fn ($node) => $node->where('uuid', $request->input('filter.node'))->orWhere('name', $request->input('filter.node'))))
            ->orderByDesc('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Backup $backup): NodeBackupResource
    {
        Gate::authorize('view', $backup);

        return new NodeBackupResource($backup->load('node:id,uuid,name'));
    }

    /**
     * Back up one node, named by its id.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node' => ['required', 'string', Rule::exists('nodes', 'uuid')],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $backup = app(CreateBackup::class)->handle($request->user(), Node::query()->where('uuid', $data['node'])->firstOrFail(), ['label' => $data['label'] ?? null]);

        return $this->accepted($request, $backup, new NodeBackupResource($backup->refresh()->load('node:id,uuid,name')), created: true);
    }

    public function destroy(Request $request, Backup $backup): JsonResponse
    {
        app(DeleteBackup::class)->handle($request->user(), $backup);

        return $this->accepted($request, $backup, null);
    }
}
