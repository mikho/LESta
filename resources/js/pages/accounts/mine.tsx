import { Head, Link } from '@inertiajs/react';
import AccountController from '@/actions/App/Http/Controllers/Accounts/AccountController';
import Heading from '@/components/heading';
import accounts from '@/routes/accounts';
import type { Account } from '@/types';

export default function Mine({
    accounts: myAccounts,
}: {
    accounts: Account[];
}) {
    return (
        <>
            <Head title="My account" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="My account"
                    description="Accounts you are a member of"
                />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                            <tr>
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">
                                    Package
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {myAccounts.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        You are not a member of any account yet.
                                    </td>
                                </tr>
                            )}

                            {myAccounts.map((account) => (
                                <tr
                                    key={account.public_id}
                                    className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                >
                                    <td className="px-4 py-2 font-medium">
                                        {account.name}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {account.package_name ?? '—'}
                                    </td>
                                    <td className="px-4 py-2">
                                        {account.suspended_at ? (
                                            <span className="text-red-600 dark:text-red-400">
                                                Suspended
                                            </span>
                                        ) : (
                                            <span className="text-green-600 dark:text-green-400">
                                                Active
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">
                                        <Link
                                            href={AccountController.show(
                                                account,
                                            )}
                                            className="text-sm font-medium underline"
                                        >
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

Mine.layout = {
    breadcrumbs: [
        {
            title: 'My account',
            href: accounts.mine(),
        },
    ],
};
