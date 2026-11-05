<?php

namespace App\Actions\Users;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webhook;

/**
 * Everything the panel holds about a person (the right of access). Secrets are left out: the
 * password hash, two-factor secrets, API token hashes and webhook signing secrets.
 */
class ExportUserData
{
    /**
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        return [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_login_ip' => $user->last_login_ip,
                'previous_login_at' => $user->previous_login_at?->toIso8601String(),
                'previous_login_ip' => $user->previous_login_ip,
            ],
            'memberships' => $user->memberships()->with(['role', 'account'])->get()->map(fn ($membership): array => [
                'account' => $membership->account->name,
                'role' => $membership->role->name,
                'since' => $membership->created_at->toIso8601String(),
            ])->all(),
            'node_admin_grants' => $user->nodeAdminGrants()->with('node')->get()->map(fn ($grant): array => [
                'node' => $grant->node->name,
                'since' => $grant->created_at?->toIso8601String(),
            ])->all(),
            'passkeys' => $user->passkeys()->get()->map(fn ($passkey): array => [
                'name' => $passkey->name,
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                'created_at' => $passkey->created_at?->toIso8601String(),
            ])->all(),
            'api_tokens' => ApiToken::query()->where('user_id', $user->getKey())->get()->map(fn (ApiToken $token): array => [
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ])->all(),
            'webhooks_created' => Webhook::query()->where('created_by', $user->getKey())->get()->map(fn (Webhook $webhook): array => [
                'url' => $webhook->url,
                'events' => $webhook->events,
                'description' => $webhook->description,
                'created_at' => $webhook->created_at?->toIso8601String(),
            ])->all(),
            'audit_events_as_actor' => AuditEvent::query()
                ->where('actor_type', $user->getMorphClass())
                ->where('actor_id', $user->getKey())
                ->orderBy('id')
                ->get()
                ->map(fn (AuditEvent $event): array => [
                    'action' => $event->action,
                    'at' => $event->created_at?->toIso8601String(),
                    'ip_address' => $event->ip_address,
                    'user_agent' => $event->user_agent,
                ])->all(),
        ];
    }
}
