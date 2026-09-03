import { Form, Head } from '@inertiajs/react';
import CronJobController from '@/actions/App/Http/Controllers/CronJobs/CronJobController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/cron-jobs';

export default function Create() {
    return (
        <>
            <Head title="Add a cron job" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Add a cron job"
                    description="Schedule a new account-scoped job for this account"
                />

                <Form {...CronJobController.store.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
                                <div className="grid gap-2">
                                    <Label htmlFor="minute">Minute</Label>
                                    <Input
                                        id="minute"
                                        name="minute"
                                        defaultValue="*"
                                    />
                                    <InputError message={errors.minute} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="hour">Hour</Label>
                                    <Input
                                        id="hour"
                                        name="hour"
                                        defaultValue="*"
                                    />
                                    <InputError message={errors.hour} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="day_of_month">Day</Label>
                                    <Input
                                        id="day_of_month"
                                        name="day_of_month"
                                        defaultValue="*"
                                    />
                                    <InputError message={errors.day_of_month} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="month">Month</Label>
                                    <Input
                                        id="month"
                                        name="month"
                                        defaultValue="*"
                                    />
                                    <InputError message={errors.month} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="day_of_week">Weekday</Label>
                                    <Input
                                        id="day_of_week"
                                        name="day_of_week"
                                        defaultValue="*"
                                    />
                                    <InputError message={errors.day_of_week} />
                                </div>
                            </div>

                            <p className="text-sm text-muted-foreground">
                                Standard cron fields (minute, hour, day of
                                month, month, day of week). Use <code>*</code>{' '}
                                for "every".
                            </p>

                            <div className="grid gap-2">
                                <Label htmlFor="command">Command</Label>

                                <Textarea
                                    id="command"
                                    name="command"
                                    required
                                    autoFocus
                                    rows={3}
                                    placeholder="php artisan backup:run"
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
                                    data-test="create-cron-job-button"
                                >
                                    Create cron job
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Create.layout = {
    breadcrumbs: [
        {
            title: 'Cron jobs',
            href: index(),
        },
        {
            title: 'Add cron job',
            href: CronJobController.create(),
        },
    ],
};
