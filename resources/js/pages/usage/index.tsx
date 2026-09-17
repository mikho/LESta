import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { NoAccountNotice } from '@/components/no-account-notice';
import accounts from '@/routes/accounts';
import usage from '@/routes/usage';
import type {
    UsageResourceType,
    UsageSnapshot,
    UsageSnapshotRollup,
} from '@/types';

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};

const resourceTypeLabels: Record<UsageResourceType, string> = {
    mail_account: 'Mailbox',
    tenant_database: 'Database',
    web_domain: 'Website',
    unknown: 'Unknown',
};

function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

function formatMonth(period: string): string {
    return new Date(`${period}T00:00:00Z`).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    });
}

function Pagination({
    paginated,
}: {
    paginated: Pick<
        Paginated<unknown>,
        'total' | 'prev_page_url' | 'next_page_url'
    >;
}) {
    return (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>{paginated.total} total</span>

            <div className="flex gap-2">
                {paginated.prev_page_url && (
                    <Link
                        href={paginated.prev_page_url}
                        preserveScroll
                        className="underline"
                    >
                        Previous
                    </Link>
                )}

                {paginated.next_page_url && (
                    <Link
                        href={paginated.next_page_url}
                        preserveScroll
                        className="underline"
                    >
                        Next
                    </Link>
                )}
            </div>
        </div>
    );
}

type ViewingAccount = {
    public_id: string;
    name: string;
};

export default function Index({
    snapshots: paginatedSnapshots,
    rollups: paginatedRollups,
    viewingAccount,
}: {
    snapshots: Paginated<UsageSnapshot> | null;
    rollups: Paginated<UsageSnapshotRollup> | null;
    viewingAccount: ViewingAccount | null;
}) {
    if (paginatedSnapshots === null || paginatedRollups === null) {
        return (
            <>
                <Head title="Usage" />

                <div className="p-4">
                    <NoAccountNotice />
                </div>
            </>
        );
    }

    return (
        <>
            <Head
                title={
                    viewingAccount ? `Usage — ${viewingAccount.name}` : 'Usage'
                }
            />

            <div className="space-y-10 p-4">
                {viewingAccount ? (
                    <Heading
                        title={`Usage for ${viewingAccount.name}`}
                        description="Real, incremental usage snapshots for this account, collected daily, plus long-range monthly history."
                    />
                ) : (
                    <Heading
                        title="Usage"
                        description="Real, incremental usage snapshots for your account, collected daily, plus long-range monthly history."
                    />
                )}

                {viewingAccount && (
                    <Link
                        href={accounts.show(viewingAccount)}
                        className="text-sm underline"
                    >
                        Back to account
                    </Link>
                )}

                <div className="space-y-3">
                    <h2 className="text-lg font-medium">Recent activity</h2>
                    <p className="text-sm text-muted-foreground">
                        The last 90 days of raw, per-collection-cycle snapshots.
                    </p>

                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Type
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Resource
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Disk
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Requests
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Bandwidth
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Collected
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {paginatedSnapshots.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            No usage collected yet.
                                        </td>
                                    </tr>
                                )}

                                {paginatedSnapshots.data.map((snapshot) => (
                                    <tr
                                        key={snapshot.uuid}
                                        className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {
                                                resourceTypeLabels[
                                                    snapshot.resource_type
                                                ]
                                            }
                                        </td>
                                        <td className="px-4 py-2 font-medium">
                                            {snapshot.resource_label}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {formatBytes(snapshot.disk_bytes)}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {snapshot.request_count ?? '—'}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {formatBytes(snapshot.bytes_sent)}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {new Date(
                                                snapshot.collected_at,
                                            ).toLocaleString()}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination paginated={paginatedSnapshots} />
                </div>

                <div className="space-y-3">
                    <h2 className="text-lg font-medium">Monthly history</h2>
                    <p className="text-sm text-muted-foreground">
                        Long-range monthly totals, kept indefinitely after a
                        month's own raw snapshots age out above.
                    </p>

                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Type
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Resource
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Disk (end of month)
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Requests
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Bandwidth
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Month
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {paginatedRollups.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            No completed months yet.
                                        </td>
                                    </tr>
                                )}

                                {paginatedRollups.data.map((rollup) => (
                                    <tr
                                        key={rollup.uuid}
                                        className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {
                                                resourceTypeLabels[
                                                    rollup.resource_type
                                                ]
                                            }
                                        </td>
                                        <td className="px-4 py-2 font-medium">
                                            {rollup.resource_label}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {formatBytes(
                                                rollup.disk_bytes_last,
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {rollup.request_count_sum ?? '—'}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {formatBytes(rollup.bytes_sent_sum)}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {formatMonth(rollup.period)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination paginated={paginatedRollups} />
                </div>
            </div>
        </>
    );
}

Index.layout = {
    breadcrumbs: [
        {
            title: 'Usage',
            href: usage.index(),
        },
    ],
};
