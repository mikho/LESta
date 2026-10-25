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
            <Head title="Your accounts" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Your accounts"
                    description="The hosting accounts you belong to. Open one to see its package, limits and members."
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
                            </tr>
                        </thead>
                        <tbody>
                            {myAccounts.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={3}
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
                                    <th
                                        scope="row"
                                        className="px-4 py-2 text-left font-medium"
                                    >
                                        <Link
                                            href={AccountController.show(
                                                account,
                                            )}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {account.name}
                                        </Link>
                                    </th>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {account.package_name ?? '—'}
                                    </td>
                                    <td className="px-4 py-2">
                                        {account.suspended_at ? (
                                            <span className="text-red-600 dark:text-red-400">
                                                Suspended
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Active
                                            </span>
                                        )}
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
            title: 'Your accounts',
            href: accounts.mine(),
        },
    ],
};
