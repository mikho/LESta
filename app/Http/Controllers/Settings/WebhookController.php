<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Webhooks\CreateWebhook;
use App\Actions\Webhooks\DeleteWebhook;
use App\Actions\Webhooks\SendWebhookTest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreWebhookRequest;
use App\Models\Account;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
    /** Recent deliveries shown with each webhook. */
    private const int DELIVERIES_SHOWN = 10;

    /**
     * The webhooks the user manages: those of the accounts they own, and the platform-wide ones
     * when they may manage those.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        Gate::authorize('viewAny', Webhook::class);

        $accounts = $this->ownedAccounts($request);
        $platform = $user->hasPermission('webhooks.manage');

        $webhooks = Webhook::query()
            ->with(['account:id,public_id,name', 'deliveries' => fn ($query) => $query->latest('id')->limit(self::DELIVERIES_SHOWN)])
            ->where(fn ($query) => $query->whereIn('account_id', $accounts->pluck('id'))->when($platform, fn ($query) => $query->orWhereNull('account_id')))
            ->latest('id')
            ->get();

        return Inertia::render('settings/webhooks', [
            'webhooks' => $webhooks->map(fn (Webhook $webhook): array => [
                'id' => $webhook->uuid,
                'url' => $webhook->url,
                'description' => $webhook->description,
                'events' => $webhook->events,
                'account' => $webhook->account === null ? null : ['public_id' => $webhook->account->public_id, 'name' => $webhook->account->name],
                'disabled_at' => $webhook->disabled_at?->toIso8601String(),
                'consecutive_failures' => $webhook->consecutive_failures,
                'created_at' => $webhook->created_at?->toIso8601String(),
                'deliveries' => $webhook->deliveries->map(fn (WebhookDelivery $delivery): array => [
                    'id' => $delivery->uuid,
                    'event' => $delivery->event,
                    'status' => $delivery->status,
                    'attempts' => $delivery->attempts,
                    'response_status' => $delivery->response_status,
                    'error' => $delivery->error,
                    'next_attempt_at' => $delivery->status === 'pending' ? $delivery->next_attempt_at?->toIso8601String() : null,
                    'created_at' => $delivery->created_at?->toIso8601String(),
                ])->all(),
            ])->all(),
            'accounts' => $accounts->map(fn (Account $account): array => ['public_id' => $account->public_id, 'name' => $account->name])->values()->all(),
            'canManagePlatform' => $platform,
            'events' => Webhook::EVENTS,
        ]);
    }

    /**
     * Register a webhook and show its signing secret once.
     */
    public function store(StoreWebhookRequest $request): RedirectResponse
    {
        $account = $request->filled('account') ? Account::query()->where('public_id', $request->validated('account'))->firstOrFail() : null;

        [, $secret] = app(CreateWebhook::class)->handle($request->user(), $account, $request->validated());

        Inertia::flash('createdWebhookSecret', $secret);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook added. Copy its signing secret now: it will not be shown again.')]);

        return to_route('webhooks.index');
    }

    public function test(Request $request, Webhook $webhook): RedirectResponse
    {
        app(SendWebhookTest::class)->handle($request->user(), $webhook);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test sent. Its result appears below in a moment.')]);

        return to_route('webhooks.index');
    }

    public function destroy(Request $request, Webhook $webhook): RedirectResponse
    {
        app(DeleteWebhook::class)->handle($request->user(), $webhook);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook removed.')]);

        return to_route('webhooks.index');
    }

    /**
     * @return Collection<int, Account>
     */
    private function ownedAccounts(Request $request)
    {
        $ownerOf = $request->user()->memberships()->whereNotNull('account_id')
            ->whereHas('role', fn ($query) => $query->where('name', 'owner'))->pluck('account_id');

        return Account::query()
            ->where('is_platform', false)
            ->where(fn ($query) => $query->whereIn('id', $ownerOf)->orWhereIn('reseller_account_id', $ownerOf))
            ->orderBy('name')
            ->get(['id', 'public_id', 'name']);
    }
}
