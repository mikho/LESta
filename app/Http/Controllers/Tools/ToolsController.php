<?php

namespace App\Http\Controllers\Tools;

use App\Concerns\ResolvesCurrentAccount;
use App\Http\Controllers\Controller;
use App\Models\WebDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ToolsController extends Controller
{
    use ResolvesCurrentAccount;

    /** Websites listed with their own shortcuts; the rest are reached from the Domains page. */
    private const int WEBSITE_LIMIT = 8;

    /**
     * One page of shortcuts to what people do most: each website's files and logs, and the
     * account-level tools (backups, usage, security, a copy of their data). Everything here
     * already has its own page; this only gathers the links.
     */
    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        if ($account === null) {
            return Inertia::render('tools/index', ['websites' => null, 'moreWebsites' => 0]);
        }

        Gate::authorize('viewAny', [WebDomain::class, $account]);

        $websites = $account->webDomains()->orderBy('domain')->limit(self::WEBSITE_LIMIT)->get(['uuid', 'domain']);

        return Inertia::render('tools/index', [
            'websites' => $websites->map(fn (WebDomain $webDomain): array => [
                'uuid' => $webDomain->uuid,
                'domain' => $webDomain->domain,
            ])->values()->all(),
            'moreWebsites' => max(0, $account->webDomains()->count() - self::WEBSITE_LIMIT),
        ]);
    }
}
