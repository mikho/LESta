import { Form, Head } from '@inertiajs/react';
import CronJobController from '@/actions/App/Http/Controllers/CronJobs/CronJobController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import cronJobs from '@/routes/cron-jobs';
import type { CronJob } from '@/types';

export default function Edit({ cronJob }: { cronJob: CronJob }) {
    return (
        <>
            <Head title="Manage cron job" />

            <div className="mx-auto w-full max-w-2xl space-y-8 p-4">
                <Heading
                    title="Manage cron job"
                    description="Schedule, command, and lifecycle controls for this cron job"
                />

                <Form
                    {...CronJobController.update.form(cronJob)}
                    className="space-y-6 rounded-lg border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
                                <div className="grid gap-2">
                                    <Label htmlFor="minute">Minute</Label>
                                    <Input
                                        id="minute"
                                        name="minute"
                                        defaultValue={cronJob.minute}
                                    />
                                    <InputError message={errors.minute} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="hour">Hour</Label>
                                    <Input
                                        id="hour"
                                        name="hour"
                                        defaultValue={cronJob.hour}
                                    />
                                    <InputError message={errors.hour} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="day_of_month">Day</Label>
                                    <Input
                                        id="day_of_month"
                                        name="day_of_month"
                                        defaultValue={cronJob.day_of_month}
                                    />
                                    <InputError message={errors.day_of_month} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="month">Month</Label>
                                    <Input
                                        id="month"
                                        name="month"
                                        defaultValue={cronJob.month}
                                    />
                                    <InputError message={errors.month} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="day_of_week">Weekday</Label>
                                    <Input
                                        id="day_of_week"
                                        name="day_of_week"
                                        defaultValue={cronJob.day_of_week}
                                    />
                                    <InputError message={errors.day_of_week} />
                                </div>
                            </div>

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
                                    Runs as a fixed, shared, non-root system
                                    user on the node. Not isolated from other
                                    accounts' jobs on the same node.
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
                        title={
                            cronJob.suspended_at
                                ? 'Unsuspend cron job'
                                : 'Suspend cron job'
                        }
                        description={
                            cronJob.suspended_at
                                ? 'Resume running this job on its own schedule.'
                                : 'Stop this job from running until it is unsuspended.'
                        }
                    />

                    <Form
                        {...(cronJob.suspended_at
                            ? CronJobController.unsuspend.form(cronJob)
                            : CronJobController.suspend.form(cronJob))}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant={
                                    cronJob.suspended_at ? 'default' : 'outline'
                                }
                                disabled={processing}
                                data-test="toggle-suspend-cron-job-button"
                            >
                                {cronJob.suspended_at ? 'Unsuspend' : 'Suspend'}
                            </Button>
                        )}
                    </Form>
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete cron job"
                        description="Delete this cron job"
                    />
                    <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                        <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                            <p className="font-medium">Warning</p>
                            <p className="text-sm">
                                Please proceed with caution, this cannot be
                                undone.
                            </p>
                        </div>

                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    variant="destructive"
                                    data-test="delete-cron-job-button"
                                >
                                    Delete cron job
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete this cron
                                    job?
                                </DialogTitle>
                                <DialogDescription>
                                    Once this cron job is deleted, its
                                    provisioning state will also be permanently
                                    deleted.
                                </DialogDescription>

                                <Form
                                    {...CronJobController.destroy.form(cronJob)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button variant="secondary">
                                                    Cancel
                                                </Button>
                                            </DialogClose>

                                            <Button
                                                variant="destructive"
                                                disabled={processing}
                                                asChild
                                            >
                                                <button
                                                    type="submit"
                                                    data-test="confirm-delete-cron-job-button"
                                                >
                                                    Delete cron job
                                                </button>
                                            </Button>
                                        </DialogFooter>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>
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
