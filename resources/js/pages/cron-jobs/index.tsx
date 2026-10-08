import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CronJobController from '@/actions/App/Http/Controllers/CronJobs/CronJobController';
import {
    CustomerResourceIndex,
    SuspensionCell,
} from '@/components/customer-resource-index';
import type {
    CustomerItem,
    CustomerListing,
} from '@/components/customer-resource-index';
import Heading from '@/components/heading';
import { NoAccountNotice } from '@/components/no-account-notice';
import {
    ProvisioningBadge,
    QuietProvisioningStatus,
} from '@/components/provisioning-badge';
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
import cronJobs from '@/routes/cron-jobs';
import customerResources from '@/routes/customer-resources';
import type { CronJob } from '@/types';

type PaginatedCronJobs = {
    data: CronJob[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};

/**
 * A plain-language summary of a job's own schedule fields, for readability only: the raw
 * fields are always what gets provisioned. Deliberately simple (a small if-chain covering the
 * most common shapes, falling back to the raw five-field string otherwise) rather than a real
 * cron-expression describer library.
 */
function describeSchedule(cronJob: CronJob): string {
    const {
        minute,
        hour,
        day_of_month: dayOfMonth,
        month,
        day_of_week: dayOfWeek,
    } = cronJob;

    if (
        minute === '*' &&
        hour === '*' &&
        dayOfMonth === '*' &&
        month === '*' &&
        dayOfWeek === '*'
    ) {
        return 'every minute';
    }

    const isPlainNumber = (value: string) => /^\d+$/.test(value);

    if (
        isPlainNumber(minute) &&
        isPlainNumber(hour) &&
        dayOfMonth === '*' &&
        month === '*' &&
        dayOfWeek === '*'
    ) {
        const pad = (value: string) => value.padStart(2, '0');

        return `daily at ${pad(hour)}:${pad(minute)}`;
    }

    return `${minute} ${hour} ${dayOfMonth} ${month} ${dayOfWeek}`;
}

export default function Index({
    customers,
    cronJobs: paginatedCronJobs,
    search: initialSearch,
}: {
    customers?: CustomerListing<CustomerItem<CronJob>>;
    cronJobs: PaginatedCronJobs | null;
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
                cronJobs.index.url(),
                { search },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [search]);

    if (customers) {
        return (
            <CustomerResourceIndex
                title="Cron jobs"
                description="Every customer's cron jobs, grouped by node and account"
                indexUrl={cronJobs.index.url()}
                listing={customers}
                emptyMessage="No customer has a cron job yet."
                resourceNoun={{ one: 'cron job', other: 'cron jobs' }}
                emptyFilteredMessage="No cron jobs match these filters."
                columns={[
                    {
                        header: 'Schedule',
                        cell: (item) => (
                            <code>{`${item.minute} ${item.hour} ${item.day_of_month} ${item.month} ${item.day_of_week}`}</code>
                        ),
                    },
                    {
                        header: 'Command',
                        sortKey: 'command',
                        cell: (item) => (
                            <Link
                                href={customerResources.show({
                                    type: 'cron-jobs',
                                    uuid: item.uuid,
                                })}
                                className="underline"
                            >
                                <code className="break-all">
                                    {item.command}
                                </code>
                            </Link>
                        ),
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
                            <QuietProvisioningStatus
                                status={item.provisioning_status}
                                reason={item.provisioning_error}
                            />
                        ),
                    },
                ]}
            />
        );
    }

    if (paginatedCronJobs === null) {
        return (
            <>
                <Head title="Cron jobs" />

                <div className="p-4">
                    <NoAccountNotice />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Cron jobs" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="Cron jobs"
                        description="Manage this account's scheduled jobs"
                    />

                    <Button asChild>
                        <Link href={CronJobController.create()}>
                            Add cron job
                        </Link>
                    </Button>
                </div>

                <Input
                    type="search"
                    placeholder="Search commands…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="max-w-sm"
                />

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                            <tr>
                                <th className="px-4 py-2 font-medium">
                                    Schedule
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Command
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
                            {paginatedCronJobs.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        No cron jobs yet.
                                    </td>
                                </tr>
                            )}

                            {paginatedCronJobs.data.map((cronJob) => (
                                <tr
                                    key={cronJob.uuid}
                                    className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                >
                                    <td className="px-4 py-2 font-medium">
                                        {describeSchedule(cronJob)}
                                    </td>
                                    <td className="max-w-xs truncate px-4 py-2 font-mono text-xs text-muted-foreground">
                                        {cronJob.command}
                                    </td>
                                    <td className="px-4 py-2">
                                        {cronJob.suspended_at ? (
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
                                            status={cronJob.provisioning_status}
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
                                                    href={CronJobController.edit(
                                                        cronJob,
                                                    )}
                                                >
                                                    Manage
                                                </Link>
                                            </Button>

                                            {cronJob.suspended_at ? (
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
                                                            Unsuspend this cron
                                                            job?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            The job will resume
                                                            running on its own
                                                            schedule.
                                                        </DialogDescription>

                                                        <Form
                                                            {...CronJobController.unsuspend.form(
                                                                cronJob,
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
                                                            Suspend this cron
                                                            job?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            The job will stop
                                                            running until it is
                                                            unsuspended.
                                                        </DialogDescription>

                                                        <Form
                                                            {...CronJobController.suspend.form(
                                                                cronJob,
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
                                                        Delete this cron job?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        This cannot be undone.
                                                        The job and its
                                                        provisioning state will
                                                        be permanently removed.
                                                    </DialogDescription>

                                                    <Form
                                                        {...CronJobController.destroy.form(
                                                            cronJob,
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
                    <span>{paginatedCronJobs.total} total</span>

                    <div className="flex gap-2">
                        {paginatedCronJobs.prev_page_url && (
                            <Link
                                href={paginatedCronJobs.prev_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Previous
                            </Link>
                        )}

                        {paginatedCronJobs.next_page_url && (
                            <Link
                                href={paginatedCronJobs.next_page_url}
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
            title: 'Cron jobs',
            href: cronJobs.index(),
        },
    ],
};
