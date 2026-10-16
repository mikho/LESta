<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dns\CreateDnsRecord;
use App\Actions\Dns\DeleteDnsRecord;
use App\Actions\Dns\SuspendDnsRecord;
use App\Actions\Dns\UnsuspendDnsRecord;
use App\Actions\Dns\UpdateDnsRecord;
use App\Http\Requests\Api\V1\PatchDnsRecordRequest;
use App\Http\Requests\Dns\StoreDnsRecordRequest;
use App\Http\Resources\Api\V1\DnsRecordResource;
use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DnsRecordController extends ApiController
{
    public function index(Request $request, Account $account, DnsZone $dnsZone): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [DnsRecord::class, $dnsZone]);

        return DnsRecordResource::collection($dnsZone->records()
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Account $account, DnsZone $dnsZone, DnsRecord $record): DnsRecordResource
    {
        Gate::authorize('view', $record);

        return new DnsRecordResource($record);
    }

    public function store(StoreDnsRecordRequest $request, Account $account, DnsZone $dnsZone): JsonResponse
    {
        $record = app(CreateDnsRecord::class)->handle($request->user(), $dnsZone, $request->validated());

        return $this->accepted($request, $record, new DnsRecordResource($record->refresh()));
    }

    public function update(PatchDnsRecordRequest $request, Account $account, DnsZone $dnsZone, DnsRecord $record): JsonResponse
    {
        app(UpdateDnsRecord::class)->handle($request->user(), $record, $request->validated());

        return $this->accepted($request, $record, new DnsRecordResource($record->refresh()));
    }

    public function suspend(Request $request, Account $account, DnsZone $dnsZone, DnsRecord $record): JsonResponse
    {
        app(SuspendDnsRecord::class)->handle($request->user(), $record);

        return $this->accepted($request, $record, new DnsRecordResource($record->refresh()));
    }

    public function unsuspend(Request $request, Account $account, DnsZone $dnsZone, DnsRecord $record): JsonResponse
    {
        app(UnsuspendDnsRecord::class)->handle($request->user(), $record);

        return $this->accepted($request, $record, new DnsRecordResource($record->refresh()));
    }

    public function destroy(Request $request, Account $account, DnsZone $dnsZone, DnsRecord $record): JsonResponse
    {
        app(DeleteDnsRecord::class)->handle($request->user(), $record);

        return $this->accepted($request, $record, null);
    }
}
