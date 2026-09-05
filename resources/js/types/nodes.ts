import type { SuspensionSource } from './domains';

export type NodeEnrollmentStatus = 'pending' | 'enrolled' | 'revoked';

export type NodeCapability = {
    id: number;
    capability: string;
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

export type Node = {
    uuid: string;
    name: string;
    hostname: string;
    enrollment_status: NodeEnrollmentStatus;
    protocol_version?: string | null;
    agent_version?: string | null;
    last_seen_at: string | null;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
    capabilities_count?: number;
    capabilities?: NodeCapability[];
    recent_operations?: NodeProvisioningOperation[];
    orphaned_identities?: OrphanedAccountNodeIdentity[];
};
