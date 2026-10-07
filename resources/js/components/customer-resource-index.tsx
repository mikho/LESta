import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import accounts from '@/routes/accounts';

export type CustomerListing<T> = {
    groups: {
        node: { uuid: string; name: string };
        accounts: {
            account: { public_id: string; name: string };
            items: T[];
        }[];
    }[];
    nodes: string[];
    filters: { node: string; account: string };
    pagination: {
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
};

export type CustomerColumn<T> = {
    header: string;
    cell: (item: T) => ReactNode;
};

const provisioningBadgeClasses: Record<string, string> = {
    pending:
        'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    dispatched:
        'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    applied:
        'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    already_applied:
        'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    rejected: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    degraded:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    unknown:
        'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400',
};

export function ProvisioningBadge({ status }: { status: string | null }) {
    const key = status ?? 'unknown';

    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${provisioningBadgeClasses[key] ?? provisioningBadgeClasses.unknown}`}
        >
            {status ? status.replace('_', ' ') : 'no operation yet'}
        </span>
    );
}

export function SuspensionCell({
    suspendedAt,
    source,
}: {
    suspendedAt: string | null;
    source: string | null;
}) {
    return suspendedAt ? (
        <span className="text-red-600 dark:text-red-400">
            Suspended{source === 'cascade' ? ' (account)' : ''}
        </span>
    ) : (
        <span className="text-green-600 dark:text-green-400">Active</span>
    );
}

/**
 * The provider admin's read-only view of every customer's resources for one list page, grouped by
 * node and then by account. The node filter is a native <datalist> input (type to narrow the
 * dropdown, or pick), the same zero-dependency choice the reseller field makes, since no combobox
 * component exists in this app. The account filter is free text.
 */
export function CustomerResourceIndex<T extends { uuid: string }>({
    title,
    description,
    indexUrl,
    listing,
    columns,
    emptyMessage,
}: {
    title: string;
    description: string;
    indexUrl: string;
    listing: CustomerListing<T>;
    columns: CustomerColumn<T>[];
    emptyMessage: string;
}) {
    const [node, setNode] = useState(listing.filters.node);
    const [account, setAccount] = useState(listing.filters.account);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                indexUrl,
                {
                    ...(node !== '' && { node }),
                    ...(account !== '' && { account }),
                },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [node, account, indexUrl]);

    return (
        <>
            <Head title={title} />

            <div className="space-y-6 p-4" data-test="customer-resource-index">
                <Heading title={title} description={description} />

                <div className="flex flex-wrap gap-3">
                    <Input
                        list="customer-node-options"
                        placeholder="All nodes"
                        value={node}
                        onChange={(e) => setNode(e.target.value)}
                        className="w-56"
                        aria-label="Filter by node"
                        data-test="customer-node-filter"
                    />
                    <datalist id="customer-node-options">
                        {listing.nodes.map((name) => (
                            <option key={name} value={name} />
                        ))}
                    </datalist>

                    <Input
                        type="search"
                        placeholder="Filter accounts by name, id or email…"
                        value={account}
                        onChange={(e) => setAccount(e.target.value)}
                        className="w-80"
                        aria-label="Filter accounts"
                        data-test="customer-account-filter"
                    />
                </div>

                {listing.groups.length === 0 && (
                    <p className="rounded-xl border border-sidebar-border/70 p-6 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                        {emptyMessage}
                    </p>
                )}

                {listing.groups.map((group) => (
                    <section key={group.node.uuid} className="space-y-4">
                        <h2 className="text-lg font-semibold">
                            Node: {group.node.name}
                        </h2>

                        {group.accounts.map(({ account: owner, items }) => (
                            <div key={owner.public_id} className="space-y-2">
                                <Link
                                    href={accounts.show({
                                        public_id: owner.public_id,
                                    })}
                                    className="text-sm font-medium underline"
                                >
                                    {owner.name}
                                </Link>

                                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                                    <table className="w-full text-left text-sm">
                                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                            <tr>
                                                {columns.map((column) => (
                                                    <th
                                                        key={column.header}
                                                        className="px-4 py-2 font-medium"
                                                    >
                                                        {column.header}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {items.map((item) => (
                                                <tr
                                                    key={item.uuid}
                                                    className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                                >
                                                    {columns.map((column) => (
                                                        <td
                                                            key={column.header}
                                                            className="px-4 py-2"
                                                        >
                                                            {column.cell(item)}
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ))}
                    </section>
                ))}

                {listing.pagination.last_page > 1 && (
                    <div className="flex items-center gap-3 text-sm">
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            disabled={!listing.pagination.prev_page_url}
                        >
                            <Link
                                href={listing.pagination.prev_page_url ?? '#'}
                            >
                                Previous
                            </Link>
                        </Button>
                        <span className="text-muted-foreground">
                            Page {listing.pagination.current_page} of{' '}
                            {listing.pagination.last_page} (
                            {listing.pagination.total} total)
                        </span>
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            disabled={!listing.pagination.next_page_url}
                        >
                            <Link
                                href={listing.pagination.next_page_url ?? '#'}
                            >
                                Next
                            </Link>
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}
