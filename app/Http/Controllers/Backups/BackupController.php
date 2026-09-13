<?php

namespace App\Http\Controllers\Backups;

use App\Actions\Backups\CreateBackup;
use App\Actions\Backups\DeleteBackup;
use App\Exceptions\NoBackupCapableNodeAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backups\StoreBackupRequest;
use App\Models\Backup;
use App\Models\Node;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BackupController extends Controller
{
    /**
     * Show the platform-wide backup list. Provider-admin-only, node-scoped (never account-scoped:
     * see Backup's own doc comment on why a real artifact today commingles every account hosted
     * on its own node), so unlike every tenant-facing index this never resolves an Account first.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Backup::class);

        $search = trim((string) $request->string('search'));

        $backups = Backup::query()
            ->with('node')
            ->when(
                $search !== '',
                fn ($query) => $query->where('label', 'like', '%'.$search.'%')
                    ->orWhereHas('node', fn ($nodeQuery) => $nodeQuery->where('name', 'like', '%'.$search.'%'))
            )
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        $backups->through(fn (Backup $backup): array => $this->presentForIndex($backup));

        return Inertia::render('backups/index', [
            'backups' => $backups,
            'search' => $search,
        ]);
    }

    /**
     * Show the form for creating a new backup: a node picker, restricted to nodes that actually
     * have an active backup.encrypted-artifacts.v1 capability (matching
     * ResolvesBackupCapableNode's own real requirement, checked again server-side in store()
     * via CreateBackup itself).
     */
    public function create(): Response
    {
        Gate::authorize('create', Backup::class);

        $nodes = Node::query()
            ->whereNull('suspended_at')
            ->whereHas('capabilities', fn ($query) => $query->where('capability', 'backup.encrypted-artifacts.v1')->whereNull('suspended_at'))
            ->orderBy('name')
            ->get(['uuid', 'name', 'hostname']);

        return Inertia::render('backups/create', [
            'nodes' => $nodes,
        ]);
    }

    /**
     * Dispatch a new backup. CreateBackup itself resolves the node's own active capability and
     * throws NoBackupCapableNodeAvailableException (a bare RuntimeException, not a
     * ValidationException: ResolvesBackupCapableNode is shared with DeleteBackup and any future
     * non-HTTP caller, so it can't assume an HTTP request context) if it has gone away between
     * the create form loading and this submit -- caught here specifically and turned into a real
     * validation error instead of a raw 500, since only this HTTP entry point can reasonably
     * offer the user a way to correct it (pick a different node).
     */
    public function store(StoreBackupRequest $request): RedirectResponse
    {
        $node = Node::where('uuid', $request->validated('node'))->firstOrFail();

        try {
            app(CreateBackup::class)->handle($request->user(), $node, ['label' => $request->validated('label')]);
        } catch (NoBackupCapableNodeAvailableException) {
            throw ValidationException::withMessages([
                'node' => __('This node no longer has an active backup capability. Choose a different node.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup dispatched.')]);

        return to_route('backups.index');
    }

    /**
     * Delete the given backup.
     */
    public function destroy(Request $request, Backup $backup): RedirectResponse
    {
        app(DeleteBackup::class)->handle($request->user(), $backup);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup deleted.')]);

        return to_route('backups.index');
    }

    /**
     * Shape a backup for the index listing. No App\Http\Resources in this app; inline shaping
     * matches the existing precedent (NodeController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(Backup $backup): array
    {
        return [
            'uuid' => $backup->uuid,
            'label' => $backup->label,
            'status' => $backup->status->value,
            'node_name' => $backup->node->name,
            'node_hostname' => $backup->node->hostname,
            'included_capabilities' => $backup->included_capabilities,
            'size_bytes' => $backup->size_bytes,
            'error_message' => $backup->error_message,
            'completed_at' => $backup->completed_at?->toIso8601String(),
            'created_at' => $backup->created_at?->toIso8601String(),
        ];
    }
}
