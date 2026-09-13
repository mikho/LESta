import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import usage from '@/routes/usage';
import type { UsageResourceType, UsageSnapshot } from '@/types';

type PaginatedUsageSnapshots = {
    data: UsageSnapshot[];
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

export default function Index({
    snapshots: paginatedSnapshots,
}: {
    snapshots: PaginatedUsageSnapshots;
}) {
    return (
        <>
            <Head title="Usage" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Usage"
                    description="Real, incremental usage snapshots for your account, collected daily. No long-range history yet — the last 90 days of raw snapshots."
                />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                            <tr>
                                <th className="px-4 py-2 font-medium">Type</th>
                                <th className="px-4 py-2 font-medium">
                                    Resource
                                </th>
                                <th className="px-4 py-2 font-medium">Disk</th>
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

                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>{paginatedSnapshots.total} total</span>

                    <div className="flex gap-2">
                        {paginatedSnapshots.prev_page_url && (
                            <Link
                                href={paginatedSnapshots.prev_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Previous
                            </Link>
                        )}

                        {paginatedSnapshots.next_page_url && (
                            <Link
                                href={paginatedSnapshots.next_page_url}
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
            title: 'Usage',
            href: usage.index(),
        },
    ],
};
