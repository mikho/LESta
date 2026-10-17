<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Packages\CreatePackage;
use App\Actions\Packages\DeletePackage;
use App\Actions\Packages\UpdatePackage;
use App\Actions\Packages\UpdatePackageQuota;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Admin\PatchPackageRequest;
use App\Http\Requests\Packages\StorePackageRequest;
use App\Http\Requests\Packages\UpdatePackageLimitRequest;
use App\Http\Resources\Api\V1\PackageResource;
use App\Models\Package;
use App\Models\PackageLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Hosting packages and their limits. The platform's own package is never listed or reachable.
 */
class PackageController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Package::class);

        return PackageResource::collection(Package::query()
            ->withoutPlatform()
            ->with('limits')
            ->withCount('accounts')
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Package $package): PackageResource
    {
        $this->customerPackage($package);
        Gate::authorize('view', $package);

        return $this->fresh($package);
    }

    /**
     * A new package has no limits: nothing can be created on it until they are set.
     */
    public function store(StorePackageRequest $request): JsonResponse
    {
        $package = app(CreatePackage::class)->handle($request->user(), $request->validated());

        return $this->accepted($request, $package, $this->fresh($package), created: true);
    }

    public function update(PatchPackageRequest $request, Package $package): JsonResponse
    {
        $this->customerPackage($package);

        app(UpdatePackage::class)->handle($request->user(), $package, $request->validated());

        return $this->accepted($request, $package, $this->fresh($package));
    }

    /**
     * Sets one limit: a number, or null for unlimited.
     */
    public function updateLimit(UpdatePackageLimitRequest $request, Package $package, string $resourceType): JsonResponse
    {
        $this->customerPackage($package);
        abort_unless(in_array($resourceType, PackageLimit::RESOURCE_TYPES, true), 404);

        app(UpdatePackageQuota::class)->handle($request->user(), $package, $resourceType, $request->validated('limit_value'));

        return $this->accepted($request, $package, $this->fresh($package));
    }

    public function destroy(Request $request, Package $package): JsonResponse
    {
        $this->customerPackage($package);

        app(DeletePackage::class)->handle($request->user(), $package);

        return response()->json(['data' => null, 'operation' => null]);
    }

    private function customerPackage(Package $package): void
    {
        abort_if($package->isPlatformPackage(), 404);
    }

    private function fresh(Package $package): PackageResource
    {
        return new PackageResource($package->refresh()->load('limits')->loadCount('accounts'));
    }
}
