export type UsageResourceType =
    'mail_account' | 'tenant_database' | 'web_domain' | 'unknown';

export type UsageSnapshot = {
    uuid: string;
    resource_type: UsageResourceType;
    resource_label: string;
    disk_bytes: number | null;
    request_count: number | null;
    bytes_sent: number | null;
    collected_at: string;
};
