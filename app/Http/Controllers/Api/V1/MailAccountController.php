<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\MailAccountResource;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MailAccountController extends ApiController
{
    public function index(Request $request, Account $account, MailDomain $mailDomain): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [MailAccount::class, $mailDomain]);

        return MailAccountResource::collection($mailDomain->accounts()
            ->with('mailDomain')
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }
}
