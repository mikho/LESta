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

    /**
     * Implemented only by backup.encrypted-artifacts.v1: restores the real, node-local data no
     * other capability's own generation history can regenerate (mail's own maildir content, each
     * database capability's own mysqldump output) from an already-local backup artifact.
     */
    case Restore = 'restore';
}
