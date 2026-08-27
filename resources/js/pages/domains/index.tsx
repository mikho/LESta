import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import WebDomainController from '@/actions/App/Http/Controllers/Domains/WebDomainController';
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
import domains from '@/routes/domains';
import type { WebDomain } from '@/types';

type PaginatedWebDomains = {
    data: WebDomain[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};

const sslModeLabels: Record<WebDomain['ssl_mode'], string> = {
    none: 'None',
    manual: 'Manual',
    lets_encrypt: "Let's Encrypt",
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
    status: WebDomain['provisioning_status'];
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
    webDomains,
    search: initialSearch,
}: {
    webDomains: PaginatedWebDomains;
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
                domains.index.url(),
                { search },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [search]);

    return (
        <>
            <Head title="Domains" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="Domains"
                        description="Manage this account's web domains"
                    />

                    <Button asChild>
                        <Link href={WebDomainController.create()}>
                            Add domain
                        </Link>
                    </Button>
                </div>

                <Input
                    type="search"
                    placeholder="Search domains…"
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
                                <th className="px-4 py-2 font-medium">
                                    Aliases
                                </th>
                                <th className="px-4 py-2 font-medium">SSL</th>
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
                            {webDomains.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        No domains yet.
                                    </td>
                                </tr>
                            )}

                            {webDomains.data.map((webDomain) => (
                                <tr
                                    key={webDomain.uuid}
                                    className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                >
                                    <td className="px-4 py-2 font-medium">
                                        {webDomain.domain}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {webDomain.aliases.length > 0
                                            ? webDomain.aliases.join(', ')
                                            : '—'}
                                    </td>
                                    <td className="px-4 py-2">
                                        {sslModeLabels[webDomain.ssl_mode]}
                                    </td>
                                    <td className="px-4 py-2">
                                        {webDomain.suspended_at ? (
                                            <span className="text-red-600 dark:text-red-400">
                                                Suspended
                                                {webDomain.suspension_source ===
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
                                            status={
                                                webDomain.provisioning_status
                                            }
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
                                                    href={WebDomainController.edit(
                                                        webDomain,
                                                    )}
                                                >
                                                    Edit
                                                </Link>
                                            </Button>

                                            {webDomain.suspended_at ? (
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
                                                            {webDomain.domain}?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            The domain will
                                                            resume serving
                                                            traffic.
                                                        </DialogDescription>

                                                        <Form
                                                            {...WebDomainController.unsuspend.form(
                                                                webDomain,
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
                                                            {webDomain.domain}?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            The domain will stop
                                                            serving traffic
                                                            until it is
                                                            unsuspended.
                                                        </DialogDescription>

                                                        <Form
                                                            {...WebDomainController.suspend.form(
                                                                webDomain,
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
                                                        Delete{' '}
                                                        {webDomain.domain}?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        This cannot be undone.
                                                        The domain and its
                                                        provisioning state will
                                                        be permanently removed.
                                                    </DialogDescription>

                                                    <Form
                                                        {...WebDomainController.destroy.form(
                                                            webDomain,
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
                    <span>{webDomains.total} total</span>

                    <div className="flex gap-2">
                        {webDomains.prev_page_url && (
                            <Link
                                href={webDomains.prev_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Previous
                            </Link>
                        )}

                        {webDomains.next_page_url && (
                            <Link
                                href={webDomains.next_page_url}
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
            title: 'Domains',
            href: domains.index(),
        },
    ],
};
