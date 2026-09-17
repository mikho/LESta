import Heading from '@/components/heading';

/**
 * Shown in place of a resource list/form whenever the current user has no
 * account-scoped membership at all (resolveAccount() returning null across
 * every tenant-facing controller: Domains, DNS, Mail, Databases, Cron jobs)
 * -- a real, valid state (a brand-new admin-created user, or a pure platform
 * admin with no account of their own), never a 404. Mirrors the dashboard's
 * own original welcome-state copy verbatim.
 */
export function NoAccountNotice() {
    return (
        <div
            className="space-y-4 rounded-xl border border-sidebar-border/70 p-8 text-center dark:border-sidebar-border"
            data-test="no-account-notice"
        >
            <Heading
                title="No hosting account"
                description="You are not a member of any account yet."
            />
            <p className="text-sm text-muted-foreground">
                An administrator or reseller needs to set up a hosting account
                for you before there is anything to manage here.
            </p>
        </div>
    );
}
