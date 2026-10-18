import type { Handoff } from '@/lib/handoff';
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
export type AdminerSessionResponse = Handoff;

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

/**
 * An extra user on a tenant database (TenantDatabaseController::edit()'s
 * `users` prop). Never a password: the panel does not keep one.
 */
export type TenantDatabaseUser = {
    uuid: string;
    label: string;
    username: string;
    access: 'full' | 'read_only';
};
