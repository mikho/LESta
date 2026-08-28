import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import DnsZoneController from '@/actions/App/Http/Controllers/Dns/DnsZoneController';
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
import dns from '@/routes/dns';
import type { DnsZone } from '@/types';

type PaginatedDnsZones = {
    data: DnsZone[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
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

function ProvisioningBadge({
    status,
}: {
    status: DnsZone['provisioning_status'];
}) {
    const key = status ?? 'unknown';

    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${provisioningBadgeClasses[key]}`}
        >
            {status ? status.replace('_', ' ') : 'no operation yet'}
        </span>
    );
}

export default function Index({
    dnsZones,
    search: initialSearch,
}: {
    dnsZones: PaginatedDnsZones;
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
                dns.index.url(),
                { search },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [search]);

    return (
        <>
            <Head title="DNS" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="DNS"
                        description="Manage this account's DNS zones"
                    />

                    <Button asChild>
                        <Link href={DnsZoneController.create()}>Add zone</Link>
                    </Button>
                </div>

                <Input
                    type="search"
                    placeholder="Search zones…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="max-w-sm"
                />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                            <tr>
                                <th className="px-4 py-2 font-medium">
                                    Domain
                                </th>
                                <th className="px-4 py-2 font-medium">TTL</th>
                                <th className="px-4 py-2 font-medium">
                                    Records
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Suspension
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Provisioning
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {dnsZones.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        No DNS zones yet.
                                    </td>
                                </tr>
                            )}

                            {dnsZones.data.map((dnsZone) => (
                                <tr
                                    key={dnsZone.uuid}
                                    className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                >
                                    <td className="px-4 py-2 font-medium">
                                        {dnsZone.domain}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {dnsZone.ttl}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {dnsZone.records_count ?? 0}
                                    </td>
                                    <td className="px-4 py-2">
                                        {dnsZone.suspended_at ? (
                                            <span className="text-red-600 dark:text-red-400">
                                                Suspended
                                                {dnsZone.suspension_source ===
                                                'cascade'
                                                    ? ' (account)'
                                                    : ''}
                                            </span>
                                        ) : (
                                            <span className="text-green-600 dark:text-green-400">
                                                Active
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">
                                        <ProvisioningBadge
                                            status={dnsZone.provisioning_status}
                                        />
                                    </td>
                                    <td className="px-4 py-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={DnsZoneController.edit(
                                                        dnsZone,
                                                    )}
                                                >
                                                    Edit
                                                </Link>
                                            </Button>

                                            {dnsZone.suspended_at ? (
                                                <Dialog>
                                                    <DialogTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                        >
                                                            Unsuspend
                                                        </Button>
                                                    </DialogTrigger>
                                                    <DialogContent>
                                                        <DialogTitle>
                                                            Unsuspend{' '}
                                                            {dnsZone.domain}?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            The zone will resume
                                                            serving DNS queries.
                                                        </DialogDescription>

                                                        <Form
                                                            {...DnsZoneController.unsuspend.form(
                                                                dnsZone,
                                                            )}
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <DialogFooter className="gap-2">
                                                                    <DialogClose
                                                                        asChild
                                                                    >
                                                                        <Button variant="secondary">
                                                                            Cancel
                                                                        </Button>
                                                                    </DialogClose>

                                                                    <Button
                                                                        disabled={
                                                                            processing
                                                                        }
                                                                        asChild
                                                                    >
                                                                        <button type="submit">
                                                                            Unsuspend
                                                                        </button>
                                                                    </Button>
                                                                </DialogFooter>
                                                            )}
                                                        </Form>
                                                    </DialogContent>
                                                </Dialog>
                                            ) : (
                                                <Dialog>
                                                    <DialogTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                        >
                                                            Suspend
                                                        </Button>
                                                    </DialogTrigger>
                                                    <DialogContent>
                                                        <DialogTitle>
                                                            Suspend{' '}
                                                            {dnsZone.domain}?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            The zone will stop
                                                            serving DNS queries
                                                            until it is
                                                            unsuspended.
                                                        </DialogDescription>

                                                        <Form
                                                            {...DnsZoneController.suspend.form(
                                                                dnsZone,
                                                            )}
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
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
                                                                            Suspend
                                                                        </button>
                                                                    </Button>
                                                                </DialogFooter>
                                                            )}
                                                        </Form>
                                                    </DialogContent>
                                                </Dialog>
                                            )}

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
                                                        Delete {dnsZone.domain}?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        This cannot be undone.
                                                        The zone, its records,
                                                        and its provisioning
                                                        state will be
                                                        permanently removed.
                                                    </DialogDescription>

                                                    <Form
                                                        {...DnsZoneController.destroy.form(
                                                            dnsZone,
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
                    <span>{dnsZones.total} total</span>

                    <div className="flex gap-2">
                        {dnsZones.prev_page_url && (
                            <Link
                                href={dnsZones.prev_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Previous
                            </Link>
                        )}

                        {dnsZones.next_page_url && (
                            <Link
                                href={dnsZones.next_page_url}
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
            title: 'DNS',
            href: dns.index(),
        },
    ],
};
