<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\AccountBackupResource;
use App\Models\Account;
use App\Models\AccountBackup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

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
}
