export type SslMode = 'none' | 'manual' | 'lets_encrypt';

export type WebServer = 'nginx' | 'apache';

export type PhpVersion = '8.1' | '8.2' | '8.3' | '8.4';

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
    web_server: WebServer;
    php_version: PhpVersion | null;
    ssl_mode: SslMode;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
    provisioning_status: ProvisioningStatus | null;
};

export type SftpAccess = {
    identityUuid: string;
    username: string;
    hasSshPublicKey: boolean;
};
