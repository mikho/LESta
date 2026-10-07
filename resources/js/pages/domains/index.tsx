import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import WebDomainController from '@/actions/App/Http/Controllers/Domains/WebDomainController';
import {
    CustomerResourceIndex,
    SuspensionCell,
} from '@/components/customer-resource-index';
import type { CustomerListing } from '@/components/customer-resource-index';
import Heading from '@/components/heading';
import { NoAccountNotice } from '@/components/no-account-notice';
import { ProvisioningBadge } from '@/components/provisioning-badge';
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

const webServerLabels: Record<WebDomain['web_server'], string> = {
    nginx: 'nginx',
    apache: 'Apache',
};

export default function Index({
    customers,
    webDomains,
    search: initialSearch,
}: {
    customers?: CustomerListing<WebDomain>;
    webDomains: PaginatedWebDomains | null;
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

    if (customers) {
        return (
            <CustomerResourceIndex
                title="Domains"
                description="Every customer's web domains, grouped by node and account"
                indexUrl={domains.index.url()}
                listing={customers}
                emptyMessage="No customer has a domain yet."
                resourceNoun={{ one: 'domain', other: 'domains' }}
                emptyFilteredMessage="No domains match these filters."
                columns={[
                    {
                        header: 'Domain',
                        cell: (item) => (
                            <span className="font-medium">{item.domain}</span>
                        ),
                    },
                    {
                        header: 'Aliases',
                        cell: (item) =>
                            item.aliases.length > 0
                                ? item.aliases.join(', ')
                                : '—',
                    },
                    {
                        header: 'SSL',
                        cell: (item) => sslModeLabels[item.ssl_mode],
                    },
                    {
                        header: 'Web server',
                        cell: (item) => webServerLabels[item.web_server],
                    },
                    {
                        header: 'Suspension',
                        cell: (item) => (
                            <SuspensionCell
                                suspendedAt={item.suspended_at}
                                source={item.suspension_source}
                            />
                        ),
                    },
                    {
                        header: 'Provisioning',
                        cell: (item) => (
                            <ProvisioningBadge
                                status={item.provisioning_status}
                            />
                        ),
                    },
                ]}
            />
        );
    }

    if (webDomains === null) {
        return (
            <>
                <Head title="Domains" />

                <div className="p-4">
                    <NoAccountNotice />
                </div>
            </>
        );
    }

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
                                    Web server
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
                            {webDomains.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={7}
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
                                        {webServerLabels[webDomain.web_server]}
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
                                            <span className="text-green-700 dark:text-green-400">
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
