export type SslMode = 'none' | 'manual' | 'lets_encrypt';

export type SuspensionSource = 'manual' | 'cascade';

export type ProvisioningStatus =
    | 'pending'
    | 'dispatched'
    | 'applied'
    | 'already_applied'
    | 'rejected'
    | 'failed'
    | 'degraded';

export type WebDomainAlias = string;

export type WebDomain = {
    uuid: string;
    domain: string;
    aliases: WebDomainAlias[];
    web_template: string;
    ssl_mode: SslMode;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
    provisioning_status: ProvisioningStatus | null;
};
