import type { ProvisioningStatus, SuspensionSource } from './domains';

export type DnsRecordType =
    'A' | 'AAAA' | 'NS' | 'CNAME' | 'MX' | 'TXT' | 'SRV' | 'PTR' | 'CAA';

export type DnsRecord = {
    uuid: string;
    name: string;
    type: DnsRecordType;
    priority: number | null;
    value: string;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
};

export type DnsZone = {
    uuid: string;
    domain: string;
    ttl: number;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
    provisioning_status: ProvisioningStatus | null;
    records_count?: number;
    records?: DnsRecord[];
};
