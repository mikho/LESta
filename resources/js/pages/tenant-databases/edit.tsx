import { Form, Head, usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import TenantDatabaseController from '@/actions/App/Http/Controllers/TenantDatabases/TenantDatabaseController';
import TenantDatabaseUserController from '@/actions/App/Http/Controllers/TenantDatabases/TenantDatabaseUserController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { apiPost } from '@/lib/api';
import { submitHandoff } from '@/lib/handoff';
import tenantDatabases from '@/routes/tenant-databases';
import type { TenantDatabase } from '@/types';
import type {
    AdminerSessionResponse,
    TenantDatabaseUser,
} from '@/types/tenant-databases';

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
                    This is the only time the password for{' '}
                    <span className="font-mono">{generated.database_user}</span>{' '}
                    is shown. It is not stored anywhere you can retrieve it
                    again -- rotate the password if it is lost.
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

/**
 * Opens Adminer for this tenant database in a new tab, leaving this page as it is.
 * TenantDatabaseController::openAdminer() is a plain JSON endpoint, not an Inertia visit (its
 * redirect target, the node's own address, is outside this app's own Inertia protocol -- see
 * that controller method's own doc comment), so this uses the same `apiPost` helper the file
 * manager's own dispatch endpoints already use, then points the new tab at the URL it returns,
 * rather than anything Inertia's router could follow. The tab is opened synchronously in the
 * click handler, before the request: opened after an `await`, browsers treat it as an
 * unrequested popup and block it. If it is blocked anyway, this falls back to navigating the
 * current tab.
 */
function OpenAdminerCard({
    tenantDatabase,
    adminerAvailable,
}: {
    tenantDatabase: TenantDatabase;
    adminerAvailable: boolean;
}) {
    const [opening, setOpening] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const openAdminer = async () => {
        setOpening(true);
        setError(null);

        const adminerTab = window.open('', '_blank');

        try {
            const handoff = await apiPost<AdminerSessionResponse>(
                TenantDatabaseController.openAdminer(tenantDatabase).url,
            );

            submitHandoff(handoff, adminerTab);
        } catch {
            adminerTab?.close();
            setError('Could not prepare an Adminer session. Try again.');
        } finally {
            setOpening(false);
        }
    };

    return (
        <div className="space-y-4 rounded-lg border p-4">
            <Heading
                variant="small"
                title="Open Adminer"
                description={
                    adminerAvailable
                        ? "Opens a one-time, auto-logged-in Adminer session for this database on this database's node."
                        : "Adminer is not available on this database's node yet. Ask your provider to enable it."
                }
            />

            {error && (
                <p className="text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}

            <Button
                variant="outline"
                disabled={!adminerAvailable || opening}
                onClick={openAdminer}
                data-test="open-adminer-button"
            >
                {opening ? 'Preparing…' : 'Open Adminer'}
            </Button>
        </div>
    );
}

const accessLabels: Record<TenantDatabaseUser['access'], string> = {
    full: 'Full access',
    read_only: 'Read only',
};

/**
 * A confirm dialog around one submit-only form: rotating a password and
 * removing a user both cut off whatever still connects as that user.
 */
function ConfirmUserAction({
    form,
    trigger,
    title,
    description,
    confirm,
    destructive = false,
}: {
    form: ReturnType<typeof TenantDatabaseUserController.destroy.form>;
    trigger: string;
    title: string;
    description: string;
    confirm: string;
    destructive?: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant={destructive ? 'ghost' : 'outline'} size="sm">
                    {trigger}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>

                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <Button
                                variant={
                                    destructive ? 'destructive' : 'default'
                                }
                                disabled={processing}
                                asChild
                            >
                                <button type="submit">{confirm}</button>
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The database's extra users: each with full or read-only access, a new
 * password on request, and removal. Changing access saves as soon as it is
 * picked. A new or rotated password appears once, in the banner above.
 */
function DatabaseUsersCard({
    tenantDatabase,
    users,
    maxUsers,
}: {
    tenantDatabase: TenantDatabase;
    users: TenantDatabaseUser[];
    maxUsers: number;
}) {
    return (
        <div className="space-y-4 rounded-lg border p-4">
            <Heading
                variant="small"
                title="Extra users"
                description="More users for this database, for another application or a person, each with full access or read-only access (it can read tables and views, never change them)."
            />

            {users.length > 0 && (
                <ul className="divide-y rounded-md border">
                    {users.map((user) => (
                        <li
                            key={user.uuid}
                            className="flex flex-wrap items-center gap-3 p-3"
                        >
                            <span className="min-w-0 flex-1 truncate font-mono text-sm">
                                {user.username}
                            </span>

                            <Form
                                {...TenantDatabaseUserController.update.form({
                                    tenantDatabase: tenantDatabase.uuid,
                                    user: user.uuid,
                                })}
                                options={{ preserveScroll: true }}
                            >
                                {({ submit, processing }) => (
                                    <select
                                        name="access"
                                        aria-label={`Access for ${user.username}`}
                                        defaultValue={user.access}
                                        disabled={processing}
                                        onChange={() => submit()}
                                        className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                                    >
                                        <option value="full">
                                            {accessLabels.full}
                                        </option>
                                        <option value="read_only">
                                            {accessLabels.read_only}
                                        </option>
                                    </select>
                                )}
                            </Form>

                            <ConfirmUserAction
                                form={TenantDatabaseUserController.rotatePassword.form(
                                    {
                                        tenantDatabase: tenantDatabase.uuid,
                                        user: user.uuid,
                                    },
                                )}
                                trigger="New password"
                                title={`Give ${user.username} a new password?`}
                                description="Anything still using the old password stops connecting until it is given the new one."
                                confirm="New password"
                            />

                            <ConfirmUserAction
                                form={TenantDatabaseUserController.destroy.form(
                                    {
                                        tenantDatabase: tenantDatabase.uuid,
                                        user: user.uuid,
                                    },
                                )}
                                trigger="Remove"
                                title={`Remove ${user.username}?`}
                                description="The user can no longer connect. The database and its data stay as they are."
                                confirm="Remove user"
                                destructive
                            />
                        </li>
                    ))}
                </ul>
            )}

            {users.length < maxUsers ? (
                <Form
                    {...TenantDatabaseUserController.store.form(
                        tenantDatabase.uuid,
                    )}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid min-w-0 flex-1 gap-2">
                                <Label htmlFor="new-database-user-label">
                                    New user
                                </Label>
                                <div className="flex items-stretch overflow-hidden rounded-md border border-input">
                                    <span className="flex items-center bg-muted px-2 font-mono text-sm text-muted-foreground">
                                        {tenantDatabase.database_name}_
                                    </span>
                                    <Input
                                        id="new-database-user-label"
                                        name="label"
                                        required
                                        maxLength={16}
                                        placeholder="report"
                                        className="rounded-none border-0 font-mono shadow-none focus-visible:ring-0"
                                    />
                                </div>
                                <InputError message={errors.label} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="new-database-user-access">
                                    Access
                                </Label>
                                <select
                                    id="new-database-user-access"
                                    name="access"
                                    defaultValue="read_only"
                                    className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="read_only">
                                        {accessLabels.read_only}
                                    </option>
                                    <option value="full">
                                        {accessLabels.full}
                                    </option>
                                </select>
                                <InputError message={errors.access} />
                            </div>

                            <Button disabled={processing}>Add user</Button>
                        </>
                    )}
                </Form>
            ) : (
                <p className="text-sm text-muted-foreground">
                    This database has the most extra users it can have (
                    {maxUsers}). Remove one to add another.
                </p>
            )}
        </div>
    );
}

export default function Edit({
    tenantDatabase,
    adminerAvailable,
    users,
    maxUsers,
}: {
    tenantDatabase: TenantDatabase;
    adminerAvailable: boolean;
    users: TenantDatabaseUser[];
    maxUsers: number;
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
                                    <span className="text-green-700 dark:text-green-400">
                                        Active
                                    </span>
                                )}
                            </dd>
                        </div>
                    </dl>
                </div>

                <OpenAdminerCard
                    tenantDatabase={tenantDatabase}
                    adminerAvailable={adminerAvailable}
                />

                <DatabaseUsersCard
                    tenantDatabase={tenantDatabase}
                    users={users}
                    maxUsers={maxUsers}
                />

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="Rotate password"
                        description="Generate a new password for this database's own user. The old password stops working immediately; existing grants are unaffected. Extra users have their own, above."
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
                        description="Delete this database, its tenant user and its extra users"
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
