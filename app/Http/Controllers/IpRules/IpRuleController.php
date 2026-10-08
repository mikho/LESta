<?php

namespace App\Http\Controllers\IpRules;

use App\Actions\IpRules\RerenderAccountWebDomains;
use App\Concerns\ResolvesCurrentAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\IpRules\StoreIpRuleRequest;
use App\Models\AccountIpRule;
use App\Models\AuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account's IP access list: addresses and ranges allowed or denied on every domain the
 * account serves through nginx. Every change re-renders those domains' vhosts.
 */
class IpRuleController extends Controller
{
    use ResolvesCurrentAccount;

    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        if ($account === null) {
            return Inertia::render('ip-rules/index', ['rules' => null, 'limit' => AccountIpRule::MAX_PER_ACCOUNT]);
        }

        Gate::authorize('viewAny', [AccountIpRule::class, $account]);

        return Inertia::render('ip-rules/index', [
            'rules' => $account->ipRules()->orderBy('id')->get()->map(fn (AccountIpRule $rule): array => [
                'uuid' => $rule->uuid,
                'action' => $rule->action,
                'cidr' => $rule->cidr,
                'note' => $rule->note,
            ])->all(),
            'limit' => AccountIpRule::MAX_PER_ACCOUNT,
        ]);
    }

    public function store(StoreIpRuleRequest $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        abort_if($account === null, 404);

        Gate::authorize('create', [AccountIpRule::class, $account]);

        if ($account->ipRules()->count() >= AccountIpRule::MAX_PER_ACCOUNT) {
            throw ValidationException::withMessages(['cidr' => __('An account can have at most :limit IP rules.', ['limit' => AccountIpRule::MAX_PER_ACCOUNT])]);
        }

        $data = $request->validated();

        if ($account->ipRules()->where('action', $data['action'])->where('cidr', $data['cidr'])->exists()) {
            throw ValidationException::withMessages(['cidr' => __('That rule already exists.')]);
        }

        $rule = $account->ipRules()->create($data);

        $this->audit($request, $rule, 'ip_rule.created');
        app(RerenderAccountWebDomains::class)->handle($account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('IP rule added.')]);

        return to_route('ip-rules.index');
    }

    public function destroy(Request $request, AccountIpRule $ipRule): RedirectResponse
    {
        Gate::authorize('delete', $ipRule);

        $account = $ipRule->account;

        $this->audit($request, $ipRule, 'ip_rule.deleted');
        $ipRule->delete();
        app(RerenderAccountWebDomains::class)->handle($account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('IP rule removed.')]);

        return to_route('ip-rules.index');
    }

    private function audit(Request $request, AccountIpRule $rule, string $action): void
    {
        AuditEvent::create([
            'actor_type' => $request->user()->getMorphClass(),
            'actor_id' => $request->user()->getKey(),
            'auditable_type' => $rule->getMorphClass(),
            'auditable_id' => $rule->getKey(),
            'action' => $action,
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
