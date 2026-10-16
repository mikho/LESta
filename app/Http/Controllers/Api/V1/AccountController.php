<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\Account;
use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    /**
     * The accounts the token reaches: the user's own memberships, the accounts managed by a
     * reseller account the user owns (the same reach hasAnyAccountMembership gives in the panel), and
     * only the one account when the token is restricted to it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        $memberOf = $user->memberships()->whereNotNull('account_id')->pluck('account_id');
        $ownerOf = $user->memberships()->whereNotNull('account_id')
            ->whereHas('role', fn ($query) => $query->where('name', 'owner'))->pluck('account_id');

        $accounts = Account::query()
            ->with('package')
            ->where(fn ($query) => $query->whereIn('id', $memberOf)->orWhereIn('reseller_account_id', $ownerOf))
            ->when($token->account_id !== null, fn ($query) => $query->whereKey($token->account_id))
            ->orderBy('id')
            ->cursorPaginate(min((int) $request->integer('limit', 50), 100));

        return AccountResource::collection($accounts);
    }
}
