<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'token' => [
                'id' => $token->uuid,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'account_id' => $token->account_id,
                'expires_at' => $token->expires_at->toIso8601ZuluString(),
            ],
        ];
    }
}
