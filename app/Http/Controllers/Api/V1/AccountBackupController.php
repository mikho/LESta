<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AccountBackups\CreateAccountBackup;
use App\Actions\AccountBackups\DeleteAccountBackup;
use App\Actions\AccountBackups\ResolvesAccountBackupScope;
use App\Actions\AccountBackups\RestoreAccountBackup;
use App\Http\Resources\Api\V1\AccountBackupResource;
use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountBackupController extends ApiController
{
    /**
     * The account's backups, newest first.
     */
    public function index(Request $request, Account $account): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [AccountBackup::class, $account]);

        return AccountBackupResource::collection($account->accountBackups()
            ->with('node:id,name')
            ->orderByDesc('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Account $account, AccountBackup $accountBackup): AccountBackupResource
    {
        Gate::authorize('view', $accountBackup);

        return new AccountBackupResource($accountBackup->load('node:id,name'));
    }

    /**
     * Back up the account's data on one node. "node" is the node's name as backups show it; it
     * may be left out when the account has data on one node only.
     */
    public function store(Request $request, Account $account): JsonResponse
    {
        Gate::authorize('create', [AccountBackup::class, $account]);

        $data = $request->validate([
            'node' => ['sometimes', 'string'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => ['string', Rule::in(AccountBackup::PARTS)],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $nodes = collect(app(ResolvesAccountBackupScope::class)->nodesWithData($account));

        $node = isset($data['node'])
            ? $nodes->first(fn (Node $node): bool => $node->name === $data['node'])
            : ($nodes->count() === 1 ? $nodes->first() : null);

        if ($node === null) {
            throw ValidationException::withMessages(['node' => isset($data['node'])
                ? __('The account has no data on that node.')
                : __('Name the node to back up: :nodes.', ['nodes' => $nodes->pluck('name')->implode(', ') ?: __('none')])]);
        }

        $backup = app(CreateAccountBackup::class)->handle($request->user(), $account, $node, $data['parts'], $data['label'] ?? null);

        return $this->accepted($request, $backup, $this->fresh($backup));
    }

    /**
     * Restore the chosen parts, overwriting the current data. A backup of the current data is
     * taken first and the restore runs when that is done, so there is no single operation to
     * follow: the answer points at the backup, whose last_restore shows the progress.
     */
    public function restore(Request $request, Account $account, AccountBackup $accountBackup): JsonResponse
    {
        Gate::authorize('restore', $accountBackup);

        $data = $request->validate([
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => ['string', Rule::in(AccountBackup::PARTS)],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => __('Confirm that you understand the restore overwrites current data.')]);

        app(RestoreAccountBackup::class)->handle($request->user(), $accountBackup, $data['parts']);

        return response()->json(['data' => $this->fresh($accountBackup)->resolve($request), 'operation' => null], 202, [
            'Location' => route('api.v1.accounts.backups.show', [$account, $accountBackup]),
        ]);
    }

    public function destroy(Request $request, Account $account, AccountBackup $accountBackup): JsonResponse
    {
        app(DeleteAccountBackup::class)->handle($request->user(), $accountBackup);

        return $this->accepted($request, $accountBackup, null);
    }

    private function fresh(AccountBackup $backup): AccountBackupResource
    {
        return new AccountBackupResource($backup->refresh()->load('node:id,name'));
    }
}
