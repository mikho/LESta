import { Head, Link } from '@inertiajs/react';
import CronJobController from '@/actions/App/Http/Controllers/CronJobs/CronJobController';
import {
    CustomerResourceIndex,
    SuspensionCell,
} from '@/components/customer-resource-index';
import type {
    CustomerItem,
    CustomerListing,
} from '@/components/customer-resource-index';
import { NoAccountNotice } from '@/components/no-account-notice';
import { OwnResourceList } from '@/components/own-resource-list';
import type { Paginated } from '@/components/own-resource-list';
import { QuietProvisioningStatus } from '@/components/provisioning-badge';
import { describeSchedule } from '@/lib/cron-schedule';
import cronJobs from '@/routes/cron-jobs';
import customerResources from '@/routes/customer-resources';
import type { CronJob } from '@/types';

export default function Index({
    customers,
    cronJobs: paginatedCronJobs,
    search: initialSearch,
}: {
    customers?: CustomerListing<CustomerItem<CronJob>>;
    cronJobs: Paginated<CronJob> | null;
    search: string;
}) {
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
        <OwnResourceList
            title="Cron jobs"
            description="Commands that run on a schedule, such as a nightly cleanup or a site's own tasks"
            addHref={CronJobController.create()}
            addLabel="Add cron job"
            indexUrl={cronJobs.index.url()}
            search={initialSearch}
            noun={{ one: 'cron job', other: 'cron jobs' }}
            items={paginatedCronJobs}
            pollProp="cronJobs"
            empty={{
                title: 'No cron jobs yet',
                body: 'Add a cron job to run a command on a schedule, for example a site task every hour.',
            }}
            name={(cronJob) => cronJob.command}
            renderName={(cronJob) => (
                <span className="block max-w-64 truncate font-mono text-xs">
                    {cronJob.command}
                </span>
            )}
            manageHref={(cronJob) => CronJobController.edit(cronJob)}
            columns={[
                {
                    header: 'Runs',
                    cell: (cronJob) => describeSchedule(cronJob),
                },
            ]}
            moreActions={(cronJob) => [
                cronJob.suspended_at
                    ? {
                          label: 'Unsuspend',
                          confirm: {
                              form: CronJobController.unsuspend.form(cronJob),
                              title: 'Unsuspend this cron job?',
                              description: 'It runs on its schedule again.',
                              confirmLabel: 'Unsuspend',
                          },
                      }
                    : {
                          label: 'Suspend',
                          confirm: {
                              form: CronJobController.suspend.form(cronJob),
                              title: 'Suspend this cron job?',
                              description:
                                  'It stops running until you unsuspend it.',
                              confirmLabel: 'Suspend',
                              destructive: true,
                          },
                      },
                {
                    label: 'Delete',
                    confirm: {
                        form: CronJobController.destroy.form(cronJob),
                        title: 'Delete this cron job?',
                        description:
                            'This cannot be undone. It stops running and its run history is removed.',
                        confirmLabel: 'Delete cron job',
                        destructive: true,
                    },
                },
            ]}
        />
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
