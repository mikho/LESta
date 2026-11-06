import { usePage } from '@inertiajs/react';
import { formatDateTime } from '@/lib/format';

/**
 * The account at a glance, on every page: which account and plan, the main domain, and the
 * previous sign-in. Hidden while the sidebar is collapsed to icons.
 */
export function AccountPanel() {
    const { sidebarAccount } = usePage().props;

    if (sidebarAccount === null) {
        return null;
    }

    return (
        <dl
            className="space-y-1 px-2 py-1 text-xs group-data-[collapsible=icon]:hidden"
            aria-label="Account overview"
            data-test="account-panel"
        >
            <div>
                <dt className="text-muted-foreground">Account</dt>
                <dd className="truncate font-medium">
                    {sidebarAccount.name} · {sidebarAccount.package}
                </dd>
            </div>
            {sidebarAccount.primary_domain !== null && (
                <div>
                    <dt className="text-muted-foreground">Main domain</dt>
                    <dd className="truncate">
                        {sidebarAccount.primary_domain}
                    </dd>
                </div>
            )}
            {sidebarAccount.previous_sign_in !== null && (
                <div>
                    <dt className="text-muted-foreground">Previous sign-in</dt>
                    <dd>{formatDateTime(sidebarAccount.previous_sign_in)}</dd>
                </div>
            )}
        </dl>
    );
}
