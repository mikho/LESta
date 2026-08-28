<?php

namespace App\Http\Controllers\Dns;

use App\Actions\Dns\CreateDnsRecord;
use App\Actions\Dns\DeleteDnsRecord;
use App\Actions\Dns\SuspendDnsRecord;
use App\Actions\Dns\UnsuspendDnsRecord;
use App\Actions\Dns\UpdateDnsRecord;
use App\Exceptions\ResourceQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\StoreDnsRecordRequest;
use App\Http\Requests\Dns\UpdateDnsRecordRequest;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DnsRecordController extends Controller
{
    /**
     * Store a newly created DNS record on the given zone.
     */
    public function store(StoreDnsRecordRequest $request, DnsZone $dnsZone): RedirectResponse
    {
        try {
            app(CreateDnsRecord::class)->handle($request->user(), $dnsZone, $request->validated());
        } catch (ResourceQuotaExceededException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DNS record added.')]);

        return to_route('dns.edit', $dnsZone);
    }

    /**
     * Update the given DNS record.
     */
    public function update(UpdateDnsRecordRequest $request, DnsZone $dnsZone, DnsRecord $dnsRecord): RedirectResponse
    {
        abort_unless($dnsRecord->dns_zone_id === $dnsZone->id, 404);

        app(UpdateDnsRecord::class)->handle($request->user(), $dnsRecord, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DNS record updated.')]);

        return to_route('dns.edit', $dnsZone);
    }

    /**
     * Delete the given DNS record.
     */
    public function destroy(Request $request, DnsZone $dnsZone, DnsRecord $dnsRecord): RedirectResponse
    {
        abort_unless($dnsRecord->dns_zone_id === $dnsZone->id, 404);

        app(DeleteDnsRecord::class)->handle($request->user(), $dnsRecord);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DNS record deleted.')]);

        return to_route('dns.edit', $dnsZone);
    }

    /**
     * Suspend the given DNS record.
     */
    public function suspend(Request $request, DnsZone $dnsZone, DnsRecord $dnsRecord): RedirectResponse
    {
        abort_unless($dnsRecord->dns_zone_id === $dnsZone->id, 404);

        app(SuspendDnsRecord::class)->handle($request->user(), $dnsRecord);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DNS record suspended.')]);

        return back();
    }

    /**
     * Unsuspend the given DNS record.
     */
    public function unsuspend(Request $request, DnsZone $dnsZone, DnsRecord $dnsRecord): RedirectResponse
    {
        abort_unless($dnsRecord->dns_zone_id === $dnsZone->id, 404);

        app(UnsuspendDnsRecord::class)->handle($request->user(), $dnsRecord);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DNS record unsuspended.')]);

        return back();
    }
}
