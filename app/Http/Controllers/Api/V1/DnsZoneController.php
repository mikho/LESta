<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\DnsZoneResource;
use App\Models\Account;
use App\Models\DnsZone;
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
}
