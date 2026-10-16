<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ApiTokens\CreateApiToken;
use App\Actions\ApiTokens\RevokeApiToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreApiTokenRequest;
use App\Models\Account;
use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    /**
     * List the signed-in user's API tokens and offer to create one.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        Gate::authorize('viewAny', ApiToken::class);

        $ownerOf = $user->memberships()->whereNotNull('account_id')
            ->whereHas('role', fn ($query) => $query->where('name', 'owner'))->pluck('account_id');

        return Inertia::render('settings/api-tokens', [
            'tokens' => $user->apiTokens()->with('account:id,name')->latest()->get()->map(fn (ApiToken $token): array => [
                'id' => $token->uuid,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'account' => $token->account?->only(['id', 'name']),
                'expires_at' => $token->expires_at->toIso8601String(),
                'expires_soon' => $token->expires_at->isBefore(now()->addDays(ApiToken::WARN_DAYS)),
                'expired' => $token->isExpired(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'last_used_ip' => $token->last_used_ip,
                'created_at' => $token->created_at?->toIso8601String(),
            ])->values(),
            'abilities' => array_values(array_diff(ApiToken::ABILITIES, $user->isPlatformUser() ? [] : ApiToken::ADMIN_ABILITIES)),
            'accounts' => Account::query()
                ->where(fn ($query) => $query->whereIn('id', $ownerOf)->orWhereIn('reseller_account_id', $ownerOf))
                ->orderBy('name')->get(['id', 'name']),
            'defaultDays' => ApiToken::DEFAULT_DAYS,
            'maxDays' => ApiToken::MAX_DAYS,
        ]);
    }

    /**
     * Create a token and show it once.
     */
    public function store(StoreApiTokenRequest $request): RedirectResponse
    {
        [, $plaintext] = app(CreateApiToken::class)->handle($request->user(), $request->validated());

        Inertia::flash('createdToken', $plaintext);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token created. Copy it now: it will not be shown again.')]);

        return to_route('api-tokens.index');
    }

    /**
     * Revoke one of the user's tokens.
     */
    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        app(RevokeApiToken::class)->handle($request->user(), $apiToken);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return to_route('api-tokens.index');
    }
}
