export type BackupStatus =
    | 'pending'
    | 'dispatched'
    | 'applied'
    | 'already_applied'
    | 'rejected'
    | 'failed'
    | 'degraded';

export type Backup = {
    uuid: string;
    label: string | null;
    status: BackupStatus;
    node_name: string;
    node_hostname: string;
    included_capabilities: string[] | null;
    size_bytes: number | null;
    error_message: string | null;
    completed_at: string | null;
    created_at: string | null;
};

export type BackupCapableNode = {
    uuid: string;
    name: string;
    hostname: string;
};
