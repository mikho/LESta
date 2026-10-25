import { Form, Head, Link } from '@inertiajs/react';
import CronJobController from '@/actions/App/Http/Controllers/CronJobs/CronJobController';
import { CronScheduleField } from '@/components/cron-schedule-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { schedulePresets } from '@/lib/cron-schedule';
import { index } from '@/routes/cron-jobs';

export default function Create() {
    return (
        <>
            <Head title="Add cron job" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Add cron job"
                    description="Run a command on a schedule, such as a nightly cleanup or a site's own task."
                />

                <Form {...CronJobController.store.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <CronScheduleField
                                initial={schedulePresets[2].schedule}
                                errors={errors}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="command">Command</Label>

                                <Textarea
                                    id="command"
                                    name="command"
                                    required
                                    rows={3}
                                    placeholder="php artisan backup:run"
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
                                    data-test="create-cron-job-button"
                                >
                                    Add cron job
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Cancel</Link>
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
