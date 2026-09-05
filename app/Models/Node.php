<?php

namespace App\Models;

use App\Concerns\Suspendable;
use App\Contracts\ProviderAdminManaged;
use App\Enums\NodeEnrollmentStatus;
use App\Enums\SuspensionSource;
use Database\Factories\NodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $hostname
 * @property string|null $enrollment_token_hash
 * @property Carbon|null $enrollment_token_expires_at
 * @property string|null $node_credential_hash
 * @property NodeEnrollmentStatus $enrollment_status
 * @property string|null $protocol_version
 * @property string|null $agent_version
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $suspended_at
 * @property SuspensionSource|null $suspension_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['uuid', 'name', 'hostname'])]
class Node extends Model implements ProviderAdminManaged
{
    /** @use HasFactory<NodeFactory> */
    use HasFactory, Suspendable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'suspension_source' => SuspensionSource::class,
            'enrollment_token_expires_at' => 'datetime',
            'enrollment_status' => NodeEnrollmentStatus::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Issue a fresh, one-time enrollment token for this node, valid for 30 minutes. The raw
     * token is returned once and never stored; only its sha256 hash is persisted, matching
     * node_credential_hash's own hashing convention below.
     */
    public function issueEnrollmentToken(): string
    {
        $token = bin2hex(random_bytes(40));

        $this->forceFill([
            'enrollment_token_hash' => hash('sha256', $token),
            'enrollment_token_expires_at' => now()->addMinutes(30),
            'enrollment_status' => NodeEnrollmentStatus::Pending,
        ])->save();

        return $token;
    }

    /**
     * Complete enrollment: mint a fresh node credential, clear the now-spent enrollment token,
     * and record the reporting agent's own version metadata. The raw credential is returned
     * once and never stored; only its sha256 hash is persisted.
     */
    public function completeEnrollment(string $protocolVersion, string $agentVersion): string
    {
        $credential = bin2hex(random_bytes(40));

        $this->forceFill([
            'node_credential_hash' => hash('sha256', $credential),
            'enrollment_token_hash' => null,
            'enrollment_token_expires_at' => null,
            'enrollment_status' => NodeEnrollmentStatus::Enrolled,
            'protocol_version' => $protocolVersion,
            'agent_version' => $agentVersion,
            'last_seen_at' => now(),
        ])->save();

        return $credential;
    }

    /**
     * @return HasMany<NodeCapability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(NodeCapability::class);
    }

    /**
     * @return HasMany<WebDomain, $this>
     */
    public function webDomains(): HasMany
    {
        return $this->hasMany(WebDomain::class);
    }

    /**
     * @return HasMany<DnsZone, $this>
     */
    public function dnsZones(): HasMany
    {
        return $this->hasMany(DnsZone::class);
    }

    /**
     * @return HasMany<TenantDatabase, $this>
     */
    public function tenantDatabases(): HasMany
    {
        return $this->hasMany(TenantDatabase::class);
    }

    /**
     * @return HasMany<CronJob, $this>
     */
    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }
}
