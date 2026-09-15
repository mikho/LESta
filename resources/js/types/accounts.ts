import type { SuspensionSource } from './domains';

export type AccountMembership = {
    id: number;
    role_name: string;
    user_name: string | null;
    user_email: string | null;
};

export type ManagedAccount = {
    public_id: string;
    name: string;
    contact_email: string | null;
};

export type Account = {
    public_id: string;
    name: string;
    contact_email: string | null;
    package_id?: number;
    package_name: string | null;
    memberships_count?: number;
    suspended_at: string | null;
    suspension_source?: SuspensionSource | null;
    created_at?: string | null;
    web_domains_count?: number;
    mail_domains_count?: number;
    tenant_databases_count?: number;
    cron_jobs_count?: number;
    memberships?: AccountMembership[];
    reseller_account_public_id?: string | null;
    reseller_account_name?: string | null;
    managed_accounts?: ManagedAccount[];
};

export type AccountPackage = {
    id: number;
    name: string;
};
