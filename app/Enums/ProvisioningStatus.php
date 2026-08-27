<?php

namespace App\Enums;

/**
 * Pending/Dispatched are Laravel-side only (not in the wire schema); the last five
 * match docs/protocol/result-envelope.schema.json's `status.enum` exactly.
 */
enum ProvisioningStatus: string
{
    case Pending = 'pending';
    case Dispatched = 'dispatched';
    case Applied = 'applied';
    case AlreadyApplied = 'already_applied';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Degraded = 'degraded';
}
