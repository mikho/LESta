<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\TenantDatabases\CreateTenantDatabaseUser;
use App\Actions\TenantDatabases\DeleteTenantDatabaseUser;
use App\Actions\TenantDatabases\RotateTenantDatabaseUserPassword;
use App\Actions\TenantDatabases\UpdateTenantDatabaseUser;
use App\Http\Requests\TenantDatabases\StoreTenantDatabaseUserRequest;
use App\Http\Requests\TenantDatabases\UpdateTenantDatabaseUserRequest;
use App\Http\Resources\Api\V1\TenantDatabaseUserResource;
use App\Models\Account;
use App\Models\TenantDatabase;
use App\Models\TenantDatabaseUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A database's extra users. Every change is an update of the database itself on its node, so
 * the operation in each answer is the database's.
 */
class TenantDatabaseUserController extends ApiController
{
    public function index(Request $request, Account $account, TenantDatabase $tenantDatabase): AnonymousResourceCollection
    {
        Gate::authorize('view', $tenantDatabase);

        return TenantDatabaseUserResource::collection($tenantDatabase->users()
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Account $account, TenantDatabase $tenantDatabase, TenantDatabaseUser $user): TenantDatabaseUserResource
    {
        Gate::authorize('view', $tenantDatabase);

        return new TenantDatabaseUserResource($user);
    }

    /**
     * The answer carries the new user's password once (data.password).
     */
    public function store(StoreTenantDatabaseUserRequest $request, Account $account, TenantDatabase $tenantDatabase): JsonResponse
    {
        /** @var array{label: string, access: string} $data */
        $data = $request->validated();
        [$user, $password] = app(CreateTenantDatabaseUser::class)->handle($request->user(), $tenantDatabase, $data);

        return $this->accepted($request, $tenantDatabase, new TenantDatabaseUserResource($user->refresh()), ['password' => $password]);
    }

    public function update(UpdateTenantDatabaseUserRequest $request, Account $account, TenantDatabase $tenantDatabase, TenantDatabaseUser $user): JsonResponse
    {
        /** @var array{access: string} $data */
        $data = $request->validated();
        app(UpdateTenantDatabaseUser::class)->handle($request->user(), $user, $data);

        return $this->accepted($request, $tenantDatabase, new TenantDatabaseUserResource($user->refresh()));
    }

    /**
     * A new password, in this answer only (data.password).
     */
    public function rotatePassword(Request $request, Account $account, TenantDatabase $tenantDatabase, TenantDatabaseUser $user): JsonResponse
    {
        $password = app(RotateTenantDatabaseUserPassword::class)->handle($request->user(), $user);

        return $this->accepted($request, $tenantDatabase, new TenantDatabaseUserResource($user), ['password' => $password]);
    }

    public function destroy(Request $request, Account $account, TenantDatabase $tenantDatabase, TenantDatabaseUser $user): JsonResponse
    {
        app(DeleteTenantDatabaseUser::class)->handle($request->user(), $user);

        return $this->accepted($request, $tenantDatabase, null);
    }
}
