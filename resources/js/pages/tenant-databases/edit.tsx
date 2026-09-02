import { Form, Head, usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import TenantDatabaseController from '@/actions/App/Http/Controllers/TenantDatabases/TenantDatabaseController';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { useClipboard } from '@/hooks/use-clipboard';
import tenantDatabases from '@/routes/tenant-databases';
import type { TenantDatabase } from '@/types';

/**
 * The one-time password banner: rendered only for the single page load that immediately
 * follows a create or a password rotation (TenantDatabaseController's own store()/
 * rotatePassword() flash 'generatedPassword' via Inertia's flash mechanism, which -- like the
 * existing 'toast' flash -- is consumed by exactly one subsequent request). Never re-appears on
 * a later reload of this same edit page.
 */
function GeneratedPasswordBanner({
    tenantDatabase,
}: {
    tenantDatabase: TenantDatabase;
}) {
    const generated = usePage().flash?.generatedPassword;
    const [copiedText, copy] = useClipboard();

    if (!generated || generated.uuid !== tenantDatabase.uuid) {
        return null;
    }

    const IconComponent = copiedText === generated.password ? Check : Copy;

    return (
        <Alert>
            <AlertTitle>Save this password now</AlertTitle>
            <AlertDescription className="w-full space-y-3">
                <p>
                    This is the only time the database password is shown. It is
                    not stored anywhere you can retrieve it again -- rotate the
                    password if it is lost.
                </p>

                <div className="flex w-full items-stretch overflow-hidden rounded-xl border border-border">
                    <input
                        type="text"
                        readOnly
                        value={generated.password}
                        className="h-full w-full bg-background p-3 font-mono text-sm text-foreground outline-none"
                    />
                    <button
                        type="button"
                        onClick={() => copy(generated.password)}
                        className="border-l border-border px-3 hover:bg-muted"
                    >
                        <IconComponent className="w-4" />
                    </button>
                </div>
            </AlertDescription>
        </Alert>
    );
}

export default function Edit({
    tenantDatabase,
}: {
    tenantDatabase: TenantDatabase;
}) {
    const [rotateOpen, setRotateOpen] = useState(false);

    return (
        <>
            <Head title={`Manage ${tenantDatabase.label}`} />

            <div className="mx-auto w-full max-w-2xl space-y-8 p-4">
                <Heading
                    title="Manage database"
                    description="Connection details and lifecycle controls for this tenant database"
                />

                <GeneratedPasswordBanner tenantDatabase={tenantDatabase} />

                <div className="space-y-4 rounded-lg border p-4">
                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Label
                            </dt>
                            <dd className="font-medium">
                                {tenantDatabase.label}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Database name
                            </dt>
                            <dd className="font-mono text-sm">
                                {tenantDatabase.database_name}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Database user
                            </dt>
                            <dd className="font-mono text-sm">
                                {tenantDatabase.database_user}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Status
                            </dt>
                            <dd>
                                {tenantDatabase.suspended_at ? (
                                    <span className="text-red-600 dark:text-red-400">
                                        Suspended
                                    </span>
                                ) : (
                                    <span className="text-green-600 dark:text-green-400">
                                        Active
                                    </span>
                                )}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="Rotate password"
                        description="Generate a new password for this database's user. The old password stops working immediately; existing grants are unaffected."
                    />

                    <Dialog open={rotateOpen} onOpenChange={setRotateOpen}>
                        <DialogTrigger asChild>
                            <Button
                                variant="outline"
                                data-test="rotate-tenant-database-password-button"
                            >
                                Rotate password
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>
                                Rotate the password for{' '}
                                {tenantDatabase.database_name}?
                            </DialogTitle>
                            <DialogDescription>
                                Any application still using the old password
                                will stop connecting until it is updated with
                                the new one.
                            </DialogDescription>

                            <Form
                                {...TenantDatabaseController.rotatePassword.form(
                                    tenantDatabase,
                                )}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setRotateOpen(false)}
                            >
                                {({ processing }) => (
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button variant="secondary">
                                                Cancel
                                            </Button>
                                        </DialogClose>

                                        <Button disabled={processing} asChild>
                                            <button type="submit">
                                                Rotate password
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title={
                            tenantDatabase.suspended_at
                                ? 'Unsuspend database'
                                : 'Suspend database'
                        }
                        description={
                            tenantDatabase.suspended_at
                                ? 'Restore access for this database.'
                                : "Revoke this database's own tenant user access until it is unsuspended."
                        }
                    />

                    <Form
                        {...(tenantDatabase.suspended_at
                            ? TenantDatabaseController.unsuspend.form(
                                  tenantDatabase,
                              )
                            : TenantDatabaseController.suspend.form(
                                  tenantDatabase,
                              ))}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant={
                                    tenantDatabase.suspended_at
                                        ? 'default'
                                        : 'outline'
                                }
                                disabled={processing}
                                data-test="toggle-suspend-tenant-database-button"
                            >
                                {tenantDatabase.suspended_at
                                    ? 'Unsuspend'
                                    : 'Suspend'}
                            </Button>
                        )}
                    </Form>
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete database"
                        description="Delete this database and its tenant user"
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
                                    data-test="delete-tenant-database-button"
                                >
                                    Delete database
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete{' '}
                                    {tenantDatabase.database_name}?
                                </DialogTitle>
                                <DialogDescription>
                                    Once this database is deleted, its schema,
                                    its tenant user, and its provisioning state
                                    will also be permanently deleted.
                                </DialogDescription>

                                <Form
                                    {...TenantDatabaseController.destroy.form(
                                        tenantDatabase,
                                    )}
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
                                                    data-test="confirm-delete-tenant-database-button"
                                                >
                                                    Delete database
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
            title: 'Databases',
            href: tenantDatabases.index(),
        },
    ],
};
