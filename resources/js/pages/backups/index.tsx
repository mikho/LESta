import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import BackupController from '@/actions/App/Http/Controllers/Backups/BackupController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import backups from '@/routes/backups';
import type { Backup } from '@/types';

type PaginatedBackups = {
    data: Backup[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};

const statusBadgeClasses: Record<Backup['status'], string> = {
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
};

function StatusBadge({ status }: { status: Backup['status'] }) {
    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${statusBadgeClasses[status]}`}
        >
            {status.replace('_', ' ')}
        </span>
    );
}

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
    backups: paginatedBackups,
    search: initialSearch,
}: {
    backups: PaginatedBackups;
    search: string;
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
                backups.index.url(),
                { search },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [search]);

    return (
        <>
            <Head title="Backups" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="Backups"
                        description="Real, encrypted, whole-node snapshots"
                    />

                    <Button asChild>
                        <Link href={BackupController.create()}>New backup</Link>
                    </Button>
                </div>

                <Input
                    type="search"
                    placeholder="Search by label or node…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="max-w-sm"
                />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                            <tr>
                                <th className="px-4 py-2 font-medium">Label</th>
                                <th className="px-4 py-2 font-medium">Node</th>
                                <th className="px-4 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-2 font-medium">Size</th>
                                <th className="px-4 py-2 font-medium">
                                    Created
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {paginatedBackups.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        No backups yet.
                                    </td>
                                </tr>
                            )}

                            {paginatedBackups.data.map((backup) => (
                                <tr
                                    key={backup.uuid}
                                    className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                >
                                    <td className="px-4 py-2 font-medium">
                                        {backup.label ?? (
                                            <span className="text-muted-foreground">
                                                (no label)
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {backup.node_name}
                                    </td>
                                    <td className="px-4 py-2">
                                        <StatusBadge status={backup.status} />
                                        {backup.error_message && (
                                            <div
                                                className="mt-1 max-w-xs truncate text-xs text-red-600 dark:text-red-400"
                                                title={backup.error_message}
                                            >
                                                {backup.error_message}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {formatBytes(backup.size_bytes)}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {backup.created_at
                                            ? new Date(
                                                  backup.created_at,
                                              ).toLocaleString()
                                            : '—'}
                                    </td>
                                    <td className="px-4 py-2">
                                        <div className="flex items-center gap-2">
                                            {(backup.status === 'applied' ||
                                                backup.status ===
                                                    'already_applied') &&
                                                (backup.download_ready ? (
                                                    <Button
                                                        asChild
                                                        variant="outline"
                                                        size="sm"
                                                    >
                                                        <a
                                                            href={
                                                                BackupController.download(
                                                                    backup,
                                                                ).url
                                                            }
                                                            title="One-time download; expires a short while after preparing"
                                                        >
                                                            Download
                                                        </a>
                                                    </Button>
                                                ) : (
                                                    <Form
                                                        {...BackupController.prepareDownload.form(
                                                            backup,
                                                        )}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={
                                                                    processing ||
                                                                    backup.download_preparing
                                                                }
                                                            >
                                                                {backup.download_preparing
                                                                    ? 'Preparing…'
                                                                    : 'Prepare download'}
                                                            </Button>
                                                        )}
                                                    </Form>
                                                ))}

                                            <Dialog>
                                                <DialogTrigger asChild>
                                                    <Button
                                                        variant="destructive"
                                                        size="sm"
                                                    >
                                                        Delete
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent>
                                                    <DialogTitle>
                                                        Delete this backup?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        This cannot be undone.
                                                        The encrypted artifact
                                                        on disk will be
                                                        permanently removed.
                                                    </DialogDescription>

                                                    <Form
                                                        {...BackupController.destroy.form(
                                                            backup,
                                                        )}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        {({ processing }) => (
                                                            <DialogFooter className="gap-2">
                                                                <DialogClose
                                                                    asChild
                                                                >
                                                                    <Button variant="secondary">
                                                                        Cancel
                                                                    </Button>
                                                                </DialogClose>

                                                                <Button
                                                                    variant="destructive"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                    asChild
                                                                >
                                                                    <button type="submit">
                                                                        Delete
                                                                    </button>
                                                                </Button>
                                                            </DialogFooter>
                                                        )}
                                                    </Form>
                                                </DialogContent>
                                            </Dialog>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>{paginatedBackups.total} total</span>

                    <div className="flex gap-2">
                        {paginatedBackups.prev_page_url && (
                            <Link
                                href={paginatedBackups.prev_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Previous
                            </Link>
                        )}

                        {paginatedBackups.next_page_url && (
                            <Link
                                href={paginatedBackups.next_page_url}
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
            title: 'Backups',
            href: backups.index(),
        },
    ],
};
