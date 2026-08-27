<?php

namespace App\Enums;

/**
 * Values match docs/protocol/operation-envelope.schema.json's `operation.enum` exactly.
 */
enum ProvisioningVerb: string
{
    case Create = 'create';
    case Update = 'update';
    case Suspend = 'suspend';
    case Unsuspend = 'unsuspend';
    case Delete = 'delete';
    case Observe = 'observe';
}
