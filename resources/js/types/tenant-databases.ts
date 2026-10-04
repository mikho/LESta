import type { ProvisioningStatus, SuspensionSource } from './domains';

export type TenantDatabase = {
    uuid: string;
    label: string;
    database_name: string;
    database_user?: string;
    suspended_at: string | null;
    suspension_source: SuspensionSource | null;
    provisioning_status: ProvisioningStatus | null;
};

/**
 * The response shape of TenantDatabaseController::openAdminer(), a plain JSON endpoint (never an
 * Inertia visit): the destination is the tenant's own domain, an origin entirely outside this
 * app's own Inertia protocol.
 */
export type AdminerSessionResponse = {
    url: string;
};

/**
 * The one-time reveal carried by TenantDatabaseController::store()'s and rotatePassword()'s own
 * Inertia flash (never a normal page prop): the only shape in this whole feature that ever
 * carries a plaintext password, and only for the single page load immediately following a create
 * or a password rotation.
 */
export type GeneratedTenantDatabasePassword = {
    uuid: string;
    label: string;
    database_name: string;
    database_user: string;
    password: string;
};
