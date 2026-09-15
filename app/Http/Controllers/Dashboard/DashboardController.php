<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the current user's own dashboard: a real resource overview for a tenant with at least
     * one account membership, or an empty/welcome state for anyone else (a pure platform admin,
     * or a brand-new admin-created user not yet assigned an account) -- resolveAccount()'s
     * firstOrFail() would otherwise 500 for that second case.
     */
    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        return Inertia::render('dashboard', [
            'account' => $account === null ? null : $this->presentAccount($account),
        ]);
    }

    private function resolveAccount(User $user): ?Account
    {
        /** @var Account|null */
        return $user->memberships()->whereNotNull('account_id')->with('account')->first()?->account;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAccount(Account $account): array
    {
        return [
            'name' => $account->name,
            'suspended_at' => $account->suspended_at?->toIso8601String(),
            'web_domains_count' => $account->webDomains()->count(),
            'dns_zones_count' => $account->dnsZones()->count(),
            'mail_domains_count' => $account->mailDomains()->count(),
            'mail_accounts_count' => MailAccount::query()
                ->whereIn('mail_domain_id', $account->mailDomains()->pluck('id'))
                ->count(),
            'tenant_databases_count' => $account->tenantDatabases()->count(),
            'cron_jobs_count' => $account->cronJobs()->count(),
        ];
    }
}
