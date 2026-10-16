<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\TenantDatabaseResource;
use App\Models\Account;
use App\Models\TenantDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TenantDatabaseController extends ApiController
{
    public function index(Request $request, Account $account): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [TenantDatabase::class, $account]);

        return TenantDatabaseResource::collection($account->tenantDatabases()
            ->with('latestProvisioningOperation')
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Account $account, TenantDatabase $tenantDatabase): TenantDatabaseResource
    {
        Gate::authorize('view', $tenantDatabase);

        return new TenantDatabaseResource($tenantDatabase->load('latestProvisioningOperation'));
    }
}
