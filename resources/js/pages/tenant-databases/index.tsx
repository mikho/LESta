import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import TenantDatabaseController from '@/actions/App/Http/Controllers/TenantDatabases/TenantDatabaseController';
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
import tenantDatabases from '@/routes/tenant-databases';
import type { TenantDatabase } from '@/types';

type PaginatedTenantDatabases = {
    data: TenantDatabase[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};

export default function Index({
    customers,
    tenantDatabases: paginatedTenantDatabases,
    search: initialSearch,
}: {
    customers?: CustomerListing<TenantDatabase>;
    tenantDatabases: PaginatedTenantDatabases | null;
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
                tenantDatabases.index.url(),
                { search },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [search]);

    if (customers) {
        return (
            <CustomerResourceIndex
                title="Databases"
                description="Every customer's databases, grouped by node and account"
                indexUrl={tenantDatabases.index.url()}
                listing={customers}
                emptyMessage="No customer has a database yet."
                resourceNoun={{ one: 'database', other: 'databases' }}
                emptyFilteredMessage="No databases match these filters."
                columns={[
                    {
                        header: 'Label',
                        cell: (item) => (
                            <span className="font-medium">{item.label}</span>
                        ),
                    },
                    { header: 'Database', cell: (item) => item.database_name },
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

    if (paginatedTenantDatabases === null) {
        return (
            <>
                <Head title="Databases" />

                <div className="p-4">
                    <NoAccountNotice />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Databases" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="Databases"
                        description="Manage this account's tenant databases"
                    />

                    <Button asChild>
                        <Link href={TenantDatabaseController.create()}>
                            Add database
                        </Link>
                    </Button>
                </div>

                <Input
                    type="search"
                    placeholder="Search databases…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="max-w-sm"
                />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                            <tr>
                                <th className="px-4 py-2 font-medium">Label</th>
                                <th className="px-4 py-2 font-medium">
                                    Database name
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
                            {paginatedTenantDatabases.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        No tenant databases yet.
                                    </td>
                                </tr>
                            )}

                            {paginatedTenantDatabases.data.map(
                                (tenantDatabase) => (
                                    <tr
                                        key={tenantDatabase.uuid}
                                        className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td className="px-4 py-2 font-medium">
                                            {tenantDatabase.label}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {tenantDatabase.database_name}
                                        </td>
                                        <td className="px-4 py-2">
                                            {tenantDatabase.suspended_at ? (
                                                <span className="text-red-600 dark:text-red-400">
                                                    Suspended
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
                                                    tenantDatabase.provisioning_status
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
                                                        href={TenantDatabaseController.edit(
                                                            tenantDatabase,
                                                        )}
                                                    >
                                                        Manage
                                                    </Link>
                                                </Button>

                                                {tenantDatabase.suspended_at ? (
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
                                                                {
                                                                    tenantDatabase.label
                                                                }
                                                                ?
                                                            </DialogTitle>
                                                            <DialogDescription>
                                                                The database
                                                                will regain
                                                                access for its
                                                                own tenant user.
                                                            </DialogDescription>

                                                            <Form
                                                                {...TenantDatabaseController.unsuspend.form(
                                                                    tenantDatabase,
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
                                                                {
                                                                    tenantDatabase.label
                                                                }
                                                                ?
                                                            </DialogTitle>
                                                            <DialogDescription>
                                                                The database's
                                                                tenant user will
                                                                lose access
                                                                until it is
                                                                unsuspended.
                                                            </DialogDescription>

                                                            <Form
                                                                {...TenantDatabaseController.suspend.form(
                                                                    tenantDatabase,
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
                                                            {
                                                                tenantDatabase.label
                                                            }
                                                            ?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            This cannot be
                                                            undone. The
                                                            database, its tenant
                                                            user, and its
                                                            provisioning state
                                                            will be permanently
                                                            removed.
                                                        </DialogDescription>

                                                        <Form
                                                            {...TenantDatabaseController.destroy.form(
                                                                tenantDatabase,
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
                                ),
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>{paginatedTenantDatabases.total} total</span>

                    <div className="flex gap-2">
                        {paginatedTenantDatabases.prev_page_url && (
                            <Link
                                href={paginatedTenantDatabases.prev_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Previous
                            </Link>
                        )}

                        {paginatedTenantDatabases.next_page_url && (
                            <Link
                                href={paginatedTenantDatabases.next_page_url}
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
            title: 'Databases',
            href: tenantDatabases.index(),
        },
    ],
};
