<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\UsageRollupResource;
use App\Http\Resources\Api\V1\UsageSnapshotResource;
use App\Models\Account;
use App\Models\UsageSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class UsageController extends ApiController
{
    /**
     * The raw readings of the last 90 days, newest first (older ones are kept only as monthly
     * rollups, see monthly()).
     */
    public function index(Request $request, Account $account): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [UsageSnapshot::class, $account]);

        return UsageSnapshotResource::collection($account->usageSnapshots()
            ->with('snapshotable')
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->cursorPaginate($this->limit($request)));
    }

    /**
     * One row per resource and month, newest month first. Gated like the raw readings: a rollup is
     * a coarser view of the same data.
     */
    public function monthly(Request $request, Account $account): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [UsageSnapshot::class, $account]);

        return UsageRollupResource::collection($account->usageSnapshotRollups()
            ->with('snapshotable')
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->cursorPaginate($this->limit($request)));
    }
}
