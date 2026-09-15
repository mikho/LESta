import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AccountController from '@/actions/App/Http/Controllers/Accounts/AccountController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import accounts from '@/routes/accounts';
import type { Account } from '@/types';

type PaginatedAccounts = {
    data: Account[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};

export default function Index({
    accounts: paginatedAccounts,
    search: initialSearch,
    canCreate,
}: {
    accounts: PaginatedAccounts;
    search: string;
    canCreate: boolean;
}) {
    const [search, setSearch] = useState(initialSearch);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                accounts.index.url(),
                { search },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [search]);

    return (
        <>
            <Head title="Accounts" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="Accounts"
                        description="Every tenant account on the platform"
                    />

                    {canCreate && (
                        <Button asChild>
                            <Link href={accounts.create()}>Create account</Link>
                        </Button>
                    )}
                </div>

                <Input
                    type="search"
                    placeholder="Search by name or contact email…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="max-w-sm"
                />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                            <tr>
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">
                                    Contact
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Package
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Members
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {paginatedAccounts.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        No accounts yet.
                                    </td>
                                </tr>
                            )}

                            {paginatedAccounts.data.map((account) => (
                                <tr
                                    key={account.public_id}
                                    className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                >
                                    <td className="px-4 py-2 font-medium">
                                        {account.name}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {account.contact_email ?? '—'}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {account.package_name ?? '—'}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {account.memberships_count ?? 0}
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
                                            Manage
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>{paginatedAccounts.total} total</span>

                    <div className="flex gap-2">
                        {paginatedAccounts.prev_page_url && (
                            <Link
                                href={paginatedAccounts.prev_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Previous
                            </Link>
                        )}

                        {paginatedAccounts.next_page_url && (
                            <Link
                                href={paginatedAccounts.next_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Next
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

Index.layout = {
    breadcrumbs: [
        {
            title: 'Accounts',
            href: accounts.index(),
        },
    ],
};
