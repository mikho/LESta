import { Form, Head } from '@inertiajs/react';
import TenantDatabaseController from '@/actions/App/Http/Controllers/TenantDatabases/TenantDatabaseController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/tenant-databases';

export default function Create() {
    return (
        <>
            <Head title="Add a database" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Add a database"
                    description="Provision a new MariaDB database and matching database user for this account"
                />

                <Form
                    {...TenantDatabaseController.store.form()}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="label">Label</Label>

                                <Input
                                    id="label"
                                    name="label"
                                    required
                                    autoFocus
                                    placeholder="app1"
                                />

                                <p className="text-sm text-muted-foreground">
                                    Lowercase letters, digits, and underscores
                                    only, starting with a letter. Used to derive
                                    the database name; it cannot be changed
                                    later.
                                </p>

                                <InputError message={errors.label} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="create-tenant-database-button"
                                >
                                    Create database
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
            title: 'Databases',
            href: index(),
        },
        {
            title: 'Add database',
            href: TenantDatabaseController.create(),
        },
    ],
};
