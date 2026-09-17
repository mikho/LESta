import type { SuspensionSource } from './domains';

export type NodeEnrollmentStatus = 'pending' | 'enrolled' | 'revoked';

export type NodeCapabilityStatus = 'not_installed' | 'running' | 'stopped';
export type NodeCapabilityDisplayStatus =
    NodeCapabilityStatus | 'suspended' | 'unknown';

export type NodeCapability = {
    id: number;
    capability: string;
    status: NodeCapabilityStatus;
    display_status: NodeCapabilityDisplayStatus;
    supports_status_tracking: boolean;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
    last_seen_at: string | null;
};

export type NodeProvisioningOperation = {
    capability: string;
    operation: string;
    status: string;
    issued_at: string;
    completed_at: string | null;
};

export type OrphanedAccountNodeIdentity = {
    uuid: string;
    system_username: string;
    account_id: number;
    account_name: string | null;
    created_at: string | null;
};

export type NodeAdminGrant = {
    uuid: string;
    user_name: string;
    user_email: string;
    created_at: string | null;
};

export type Node = {
    uuid: string;
    name: string;
    hostname: string;
    enrollment_status: NodeEnrollmentStatus;
    protocol_version?: string | null;
    agent_version?: string | null;
    last_seen_at: string | null;
    agent_reachable?: boolean;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
    backups_scheduled?: boolean;
    capabilities_count?: number;
    capabilities?: NodeCapability[];
    recent_operations?: NodeProvisioningOperation[];
    orphaned_identities?: OrphanedAccountNodeIdentity[];
    admin_grants?: NodeAdminGrant[];
};
