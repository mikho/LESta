<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\TenantDatabases\CreateTenantDatabase;
use App\Actions\TenantDatabases\DeleteTenantDatabase;
use App\Actions\TenantDatabases\RotateTenantDatabasePassword;
use App\Actions\TenantDatabases\SuspendTenantDatabase;
use App\Actions\TenantDatabases\UnsuspendTenantDatabase;
use App\Http\Requests\TenantDatabases\StoreTenantDatabaseRequest;
use App\Http\Resources\Api\V1\TenantDatabaseResource;
use App\Models\Account;
use App\Models\TenantDatabase;
use Illuminate\Http\JsonResponse;
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

    /**
     * The answer carries the database user's password once (data.password).
     */
    public function store(StoreTenantDatabaseRequest $request, Account $account): JsonResponse
    {
        [$tenantDatabase, $password] = app(CreateTenantDatabase::class)->handle($request->user(), $account, $request->validated());

        return $this->accepted($request, $tenantDatabase, $this->fresh($tenantDatabase), ['password' => $password]);
    }

    /**
     * A new password, in this answer only (data.password).
     */
    public function rotatePassword(Request $request, Account $account, TenantDatabase $tenantDatabase): JsonResponse
    {
        [, $password] = app(RotateTenantDatabasePassword::class)->handle($request->user(), $tenantDatabase);

        return $this->accepted($request, $tenantDatabase, $this->fresh($tenantDatabase), ['password' => $password]);
    }

    public function suspend(Request $request, Account $account, TenantDatabase $tenantDatabase): JsonResponse
    {
        app(SuspendTenantDatabase::class)->handle($request->user(), $tenantDatabase);

        return $this->accepted($request, $tenantDatabase, $this->fresh($tenantDatabase));
    }

    public function unsuspend(Request $request, Account $account, TenantDatabase $tenantDatabase): JsonResponse
    {
        app(UnsuspendTenantDatabase::class)->handle($request->user(), $tenantDatabase);

        return $this->accepted($request, $tenantDatabase, $this->fresh($tenantDatabase));
    }

    public function destroy(Request $request, Account $account, TenantDatabase $tenantDatabase): JsonResponse
    {
        app(DeleteTenantDatabase::class)->handle($request->user(), $tenantDatabase);

        return $this->accepted($request, $tenantDatabase, null);
    }

    private function fresh(TenantDatabase $tenantDatabase): TenantDatabaseResource
    {
        return new TenantDatabaseResource($tenantDatabase->refresh()->load('latestProvisioningOperation'));
    }
}
