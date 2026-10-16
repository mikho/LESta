<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dns\CreateDnsZone;
use App\Actions\Dns\DeleteDnsZone;
use App\Actions\Dns\SuspendDnsZone;
use App\Actions\Dns\UnsuspendDnsZone;
use App\Actions\Dns\UpdateDnsZone;
use App\Http\Requests\Api\V1\PatchDnsZoneRequest;
use App\Http\Requests\Dns\StoreDnsZoneRequest;
use App\Http\Resources\Api\V1\DnsZoneResource;
use App\Models\Account;
use App\Models\DnsZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DnsZoneController extends ApiController
{
    public function index(Request $request, Account $account): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [DnsZone::class, $account]);

        $request->validate(['filter.domain' => ['sometimes', 'string']]);

        return DnsZoneResource::collection($account->dnsZones()
            ->withCount('records')
            ->with('latestProvisioningOperation')
            ->when($request->filled('filter.domain'), fn ($query) => $query->where('domain', $request->string('filter.domain')->lower()->toString()))
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Account $account, DnsZone $dnsZone): DnsZoneResource
    {
        Gate::authorize('view', $dnsZone);

        return new DnsZoneResource($dnsZone->loadCount('records')->load('latestProvisioningOperation'));
    }

    public function store(StoreDnsZoneRequest $request, Account $account): JsonResponse
    {
        $dnsZone = app(CreateDnsZone::class)->handle($request->user(), $account, $request->validated());

        return $this->accepted($request, $dnsZone, $this->fresh($dnsZone));
    }

    public function update(PatchDnsZoneRequest $request, Account $account, DnsZone $dnsZone): JsonResponse
    {
        app(UpdateDnsZone::class)->handle($request->user(), $dnsZone, $request->validated());

        return $this->accepted($request, $dnsZone, $this->fresh($dnsZone));
    }

    public function suspend(Request $request, Account $account, DnsZone $dnsZone): JsonResponse
    {
        app(SuspendDnsZone::class)->handle($request->user(), $dnsZone);

        return $this->accepted($request, $dnsZone, $this->fresh($dnsZone));
    }

    public function unsuspend(Request $request, Account $account, DnsZone $dnsZone): JsonResponse
    {
        app(UnsuspendDnsZone::class)->handle($request->user(), $dnsZone);

        return $this->accepted($request, $dnsZone, $this->fresh($dnsZone));
    }

    public function destroy(Request $request, Account $account, DnsZone $dnsZone): JsonResponse
    {
        app(DeleteDnsZone::class)->handle($request->user(), $dnsZone);

        return $this->accepted($request, $dnsZone, null);
    }

    private function fresh(DnsZone $dnsZone): DnsZoneResource
    {
        return new DnsZoneResource($dnsZone->refresh()->loadCount('records')->load('latestProvisioningOperation'));
    }
}
