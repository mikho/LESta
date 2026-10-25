import { Form, Head } from '@inertiajs/react';
import CronJobController from '@/actions/App/Http/Controllers/CronJobs/CronJobController';
import { ConfirmActionDialog } from '@/components/confirm-action-dialog';
import { CronScheduleField } from '@/components/cron-schedule-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { ProvisioningPanel } from '@/components/provisioning-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { isApplying, usePollWhile } from '@/hooks/use-poll-while';
import { describeSchedule } from '@/lib/cron-schedule';
import cronJobs from '@/routes/cron-jobs';
import type { CronJob, CronJobExecution } from '@/types';

const exitCodeBadgeClasses = (exitCode: number): string =>
    exitCode === 0
        ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
        : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';

function ExecutionRow({ execution }: { execution: CronJobExecution }) {
    return (
        <details className="rounded-lg border p-3">
            <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2">
                <span className="text-sm">
                    {new Date(execution.started_at).toLocaleString()}
                </span>
                <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${exitCodeBadgeClasses(execution.exit_code)}`}
                >
                    exit {execution.exit_code}
                </span>
            </summary>

            <div className="mt-2 space-y-2 text-xs text-muted-foreground">
                <p>
                    Finished {new Date(execution.finished_at).toLocaleString()}
                </p>

                <pre className="max-h-64 overflow-auto rounded-md bg-muted p-2 whitespace-pre-wrap">
                    {execution.output || '(no output)'}
                </pre>
            </div>
        </details>
    );
}

export default function Edit({ cronJob }: { cronJob: CronJob }) {
    usePollWhile(isApplying(cronJob.provisioning_status), ['cronJob']);

    return (
        <>
            <Head title="Cron job" />

            <div className="mx-auto w-full max-w-2xl space-y-8 p-4">
                <Heading
                    level={1}
                    title="Cron job"
                    description={`${describeSchedule(cronJob)}: ${cronJob.command}`}
                />

                <ProvisioningPanel
                    status={cronJob.provisioning_status}
                    reason={cronJob.provisioning_error}
                    updatedAt={cronJob.provisioning_updated_at}
                />

                <Form
                    {...CronJobController.update.form(cronJob)}
                    className="space-y-6 rounded-lg border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <CronScheduleField
                                initial={cronJob}
                                errors={errors}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="command">Command</Label>

                                <Textarea
                                    id="command"
                                    name="command"
                                    required
                                    rows={3}
                                    defaultValue={cronJob.command}
                                />

                                <p className="text-sm text-muted-foreground">
                                    Runs as your account's own user on the
                                    server, never as root, and can reach only
                                    your own files.
                                </p>

                                <InputError message={errors.command} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-cron-job-button"
                                >
                                    Save changes
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="Recent runs"
                        description="What happened the last times this job ran: when, its exit code and its output."
                    />

                    {cronJob.executions && cronJob.executions.length > 0 ? (
                        <div className="space-y-2">
                            {cronJob.executions.map((execution) => (
                                <ExecutionRow
                                    key={execution.started_at}
                                    execution={execution}
                                />
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            It has not run yet.
                        </p>
                    )}
                </div>

                <section
                    aria-labelledby="danger-heading"
                    className="space-y-4 rounded-lg border border-red-200 p-4 dark:border-red-900"
                >
                    <Heading
                        variant="small"
                        id="danger-heading"
                        title="Suspend or delete"
                        description="Suspending stops the job running until you unsuspend it. Deleting removes it and its run history for good."
                    />

                    <div className="flex flex-wrap gap-2">
                        {cronJob.suspended_at ? (
                            <ConfirmActionDialog
                                form={CronJobController.unsuspend.form(cronJob)}
                                title="Unsuspend this cron job?"
                                description="It runs on its schedule again."
                                confirmLabel="Unsuspend"
                                confirmTestId="confirm-toggle-suspend-cron-job-button"
                                trigger={
                                    <Button data-test="toggle-suspend-cron-job-button">
                                        Unsuspend cron job
                                    </Button>
                                }
                            />
                        ) : (
                            <ConfirmActionDialog
                                form={CronJobController.suspend.form(cronJob)}
                                title="Suspend this cron job?"
                                description="It stops running until you unsuspend it."
                                confirmLabel="Suspend"
                                destructive
                                confirmTestId="confirm-toggle-suspend-cron-job-button"
                                trigger={
                                    <Button
                                        variant="outline"
                                        data-test="toggle-suspend-cron-job-button"
                                    >
                                        Suspend cron job
                                    </Button>
                                }
                            />
                        )}

                        <ConfirmActionDialog
                            form={CronJobController.destroy.form(cronJob)}
                            title="Delete this cron job?"
                            description="This cannot be undone. It stops running and its run history is removed."
                            confirmLabel="Delete cron job"
                            destructive
                            confirmTestId="confirm-delete-cron-job-button"
                            trigger={
                                <Button
                                    variant="destructive"
                                    data-test="delete-cron-job-button"
                                >
                                    Delete cron job
                                </Button>
                            }
                        />
                    </div>
                </section>
            </div>
        </>
    );
}

Edit.layout = {
    breadcrumbs: [
        {
            title: 'Cron jobs',
            href: cronJobs.index(),
        },
    ],
};
