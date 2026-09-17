<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'is_provider_admin' => $request->user()?->isProviderAdmin() ?? false,
                // A user with no platform role can still be a delegated admin of one or more
                // nodes (see App\Models\NodeAdminGrant); the sidebar's own Nodes section stays
                // visible for them, scoped to just their own granted node(s), not the full fleet.
                'has_node_admin_grants' => $request->user()?->nodeAdminGrants()->exists() ?? false,
                // Real self-service account visibility: a user with at least one real membership
                // (owner or member) sees a "My account" nav entry pointing at their own account(s),
                // independent of any admin/node-admin capacity they may also hold.
                'has_any_account_membership' => $request->user()?->memberships()->whereNotNull('account_id')->exists() ?? false,
                // Set by App\Actions\Support\StartImpersonation (a real session swap, not the
                // separate read-only support view) for the persistent "you are impersonating X"
                // banner every page needs while it's active, and the admin's own name to return
                // to via App\Actions\Support\StopImpersonation.
                'impersonating' => $this->impersonating($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array{admin_name: string}|null
     */
    private function impersonating(Request $request): ?array
    {
        $adminId = $request->session()->get('impersonator_id');

        if ($adminId === null) {
            return null;
        }

        $admin = User::query()->find((int) $adminId);

        return $admin === null ? null : ['admin_name' => $admin->name];
    }
}
