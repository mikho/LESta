<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MailAccount;
use Illuminate\Http\Request;

/**
 * A mailbox. Never the password: that is returned only by the call that creates or rotates it.
 *
 * @mixin MailAccount
 */
class MailAccountResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'local_part' => $this->local_part,
            'address' => $this->local_part.'@'.$this->mailDomain->domain,
            'quota_mb' => $this->quota_mb,
            'forward_to' => $this->forward_to,
            'forward_only' => $this->forward_only,
            'autoreply_enabled' => $this->autoreply_enabled,
            'autoreply_message' => $this->autoreply_message,
            ...$this->suspension($this->suspended_at, $this->suspension_source),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
