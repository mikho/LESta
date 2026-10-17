<?php

namespace App\Http\Controllers\Packages;

use App\Actions\Packages\CreatePackage;
use App\Actions\Packages\DeletePackage;
use App\Actions\Packages\UpdatePackage;
use App\Actions\Packages\UpdatePackageQuota;
use App\Http\Controllers\Controller;
use App\Http\Requests\Packages\StorePackageRequest;
use App\Http\Requests\Packages\UpdatePackageLimitRequest;
use App\Http\Requests\Packages\UpdatePackageRequest;
use App\Models\Package;
use App\Models\PackageLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    /**
     * Show the platform-wide package list.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', Package::class);

        $packages = Package::query()
            ->withoutPlatform()
            ->withCount('accounts')
            ->orderBy('name')
            ->get();

        return Inertia::render('packages/index', [
            'packages' => $packages->map(fn (Package $package): array => $this->presentForIndex($package))->all(),
        ]);
    }

    /**
     * Show the form for creating a new package.
     */
    public function create(): Response
    {
        Gate::authorize('create', Package::class);

        return Inertia::render('packages/create');
    }

    /**
     * Store a newly created package.
     */
    public function store(StorePackageRequest $request): RedirectResponse
    {
        $package = app(CreatePackage::class)->handle($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Package created.')]);

        return to_route('packages.edit', $package);
    }

    /**
     * Show the form for editing a package and its quotas.
     */
    public function edit(Package $package): Response
    {
        Gate::authorize('update', $package);

        $package->load('limits');

        return Inertia::render('packages/edit', [
            'package' => $this->presentForEdit($package),
        ]);
    }

    /**
     * Update the given package.
     */
    public function update(UpdatePackageRequest $request, Package $package): RedirectResponse
    {
        app(UpdatePackage::class)->handle($request->user(), $package, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Package updated.')]);

        return to_route('packages.edit', $package);
    }

    /**
     * Set the given package's own limit for one resource type.
     */
    public function updateLimit(UpdatePackageLimitRequest $request, Package $package, string $resourceType): RedirectResponse
    {
        abort_unless(in_array($resourceType, PackageLimit::RESOURCE_TYPES, true), 404);

        app(UpdatePackageQuota::class)->handle(
            $request->user(),
            $package,
            $resourceType,
            $request->validated('limit_value'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quota updated.')]);

        return to_route('packages.edit', $package);
    }

    /**
     * Delete the given package.
     */
    public function destroy(Request $request, Package $package): RedirectResponse
    {
        app(DeletePackage::class)->handle($request->user(), $package);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Package deleted.')]);

        return to_route('packages.index');
    }

    /**
     * Shape a package for the index listing. No App\Http\Resources in this app; inline shaping
     * matches the existing precedent (NodeController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(Package $package): array
    {
        return [
            'uuid' => $package->uuid,
            'name' => $package->name,
            'description' => $package->description,
            'is_active' => $package->is_active,
            'accounts_count' => $package->accounts_count,
        ];
    }

    /**
     * Shape a package, with every real quota-enforced resource type's own current limit, for the
     * edit page. A resource type with no PackageLimit row at all is reported as blocked (limit 0,
     * configured false), matching how a missing row actually behaves -- never presented as
     * unlimited, which is what a genuinely null limit_value means instead.
     *
     * @return array<string, mixed>
     */
    private function presentForEdit(Package $package): array
    {
        $limitsByResourceType = $package->limits->keyBy('resource_type');

        return [
            'uuid' => $package->uuid,
            'name' => $package->name,
            'description' => $package->description,
            'is_active' => $package->is_active,
            'limits' => collect(PackageLimit::RESOURCE_TYPES)->map(function (string $resourceType) use ($limitsByResourceType): array {
                /** @var PackageLimit|null $limit */
                $limit = $limitsByResourceType->get($resourceType);

                return [
                    'resource_type' => $resourceType,
                    'configured' => $limit !== null,
                    'limit_value' => $limit?->limit_value,
                ];
            })->all(),
        ];
    }
}
