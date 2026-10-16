<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\DnsRecordResource;
use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsZone;
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
}
