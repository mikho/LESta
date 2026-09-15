import type { ProvisioningStatus, SuspensionSource } from './domains';

export type MailAccount = {
    uuid: string;
    local_part: string;
    quota_mb: number | null;
    forward_to: string | null;
    forward_only: boolean;
    autoreply_enabled: boolean;
    autoreply_message: string | null;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
};

export type MailDomain = {
    uuid: string;
    domain: string;
    antivirus_enabled: boolean;
    antispam_enabled: boolean;
    dkim_enabled: boolean;
    dkim_selector?: string;
    dkim_selector_activated_at?: string | null;
    catchall_email?: string | null;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
    provisioning_status: ProvisioningStatus | null;
    accounts_count?: number;
    accounts?: MailAccount[];
};
