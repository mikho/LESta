<?php

namespace App\Http\Controllers\Dns;

use App\Actions\Dns\CreateDnsZone;
use App\Actions\Dns\DeleteDnsZone;
use App\Actions\Dns\SuspendDnsZone;
use App\Actions\Dns\UnsuspendDnsZone;
use App\Actions\Dns\UpdateDnsZone;
use App\Exceptions\ResourceQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\StoreDnsZoneRequest;
use App\Http\Requests\Dns\UpdateDnsZoneRequest;
use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DnsZoneController extends Controller
{
    /**
     * Show the account's DNS zone list.
     */
    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        Gate::authorize('viewAny', [DnsZone::class, $account]);

        $search = trim((string) $request->string('search'));

        $dnsZones = $account->dnsZones()
            ->withCount('records')
            ->when($search !== '', fn ($query) => $query->where('domain', 'like', '%'.$search.'%'))
            ->orderBy('domain')
            ->paginate(15)
            ->withQueryString();

        $dnsZones->through(fn (DnsZone $dnsZone): array => $this->presentForIndex($dnsZone));

        return Inertia::render('dns/index', [
            'dnsZones' => $dnsZones,
            'search' => $search,
        ]);
    }

    /**
     * Show the form for creating a new DNS zone.
     */
    public function create(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        Gate::authorize('create', [DnsZone::class, $account]);

        return Inertia::render('dns/create');
    }

    /**
     * Store a newly created DNS zone.
     */
    public function store(StoreDnsZoneRequest $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        try {
            app(CreateDnsZone::class)->handle($request->user(), $account, $request->validated());
        } catch (ResourceQuotaExceededException $e) {
            throw ValidationException::withMessages(['domain' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DNS zone created.')]);

        return to_route('dns.index');
    }

    /**
     * Show the form for editing a DNS zone.
     */
    public function edit(Request $request, DnsZone $dnsZone): Response
    {
        Gate::authorize('update', $dnsZone);

        $dnsZone->load(['records', 'latestProvisioningOperation']);

        return Inertia::render('dns/edit', [
            'dnsZone' => $this->presentForEdit($dnsZone),
        ]);
    }

    /**
     * Update the given DNS zone.
     */
    public function update(UpdateDnsZoneRequest $request, DnsZone $dnsZone): RedirectResponse
    {
        app(UpdateDnsZone::class)->handle($request->user(), $dnsZone, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DNS zone updated.')]);

        return to_route('dns.edit', $dnsZone);
    }

    /**
     * Suspend the given DNS zone.
     */
    public function suspend(Request $request, DnsZone $dnsZone): RedirectResponse
    {
        app(SuspendDnsZone::class)->handle($request->user(), $dnsZone);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DNS zone suspended.')]);

        return back();
    }

    /**
     * Unsuspend the given DNS zone.
     */
    public function unsuspend(Request $request, DnsZone $dnsZone): RedirectResponse
    {
        app(UnsuspendDnsZone::class)->handle($request->user(), $dnsZone);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DNS zone unsuspended.')]);

        return back();
    }

    /**
     * Delete the given DNS zone.
     */
    public function destroy(Request $request, DnsZone $dnsZone): RedirectResponse
    {
        app(DeleteDnsZone::class)->handle($request->user(), $dnsZone);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DNS zone deleted.')]);

        return to_route('dns.index');
    }

    /**
     * Resolve the acting account: the user's first account-scoped membership. There is no
     * account switcher yet, so a user belonging to multiple accounts is limited to the first
     * one, a known limitation, not a silent gap.
     */
    private function resolveAccount(User $user): Account
    {
        /** @var Account */
        return $user->memberships()->whereNotNull('account_id')->with('account')->firstOrFail()->account;
    }

    /**
     * Shape a DNS zone for the index listing. No App\Http\Resources in this app; inline shaping
     * matches the existing precedent (WebDomainController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(DnsZone $dnsZone): array
    {
        return [
            'uuid' => $dnsZone->uuid,
            'domain' => $dnsZone->domain,
            'ttl' => $dnsZone->ttl,
            'suspended_at' => $dnsZone->suspended_at?->toIso8601String(),
            'suspension_source' => $dnsZone->suspension_source?->value,
            'provisioning_status' => $dnsZone->latestProvisioningOperation?->status->value,
            'records_count' => $dnsZone->records_count,
        ];
    }

    /**
     * Shape a DNS zone, with its records, for the edit page.
     *
     * @return array<string, mixed>
     */
    private function presentForEdit(DnsZone $dnsZone): array
    {
        return [
            'uuid' => $dnsZone->uuid,
            'domain' => $dnsZone->domain,
            'ttl' => $dnsZone->ttl,
            'suspended_at' => $dnsZone->suspended_at?->toIso8601String(),
            'suspension_source' => $dnsZone->suspension_source?->value,
            'provisioning_status' => $dnsZone->latestProvisioningOperation?->status->value,
            'records' => $dnsZone->records->map(fn (DnsRecord $record): array => $this->presentRecord($record))->all(),
        ];
    }

    /**
     * Shape a DNS record for the frontend.
     *
     * @return array<string, mixed>
     */
    private function presentRecord(DnsRecord $record): array
    {
        return [
            'uuid' => $record->uuid,
            'name' => $record->name,
            'type' => $record->type->value,
            'priority' => $record->priority,
            'value' => $record->value,
            'suspended_at' => $record->suspended_at?->toIso8601String(),
            'suspension_source' => $record->suspension_source?->value,
        ];
    }
}
