import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import TenantDatabaseController from '@/actions/App/Http/Controllers/TenantDatabases/TenantDatabaseController';
import TenantDatabaseUserController from '@/actions/App/Http/Controllers/TenantDatabases/TenantDatabaseUserController';
import { ConfirmActionDialog } from '@/components/confirm-action-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { OneTimeSecretBanner } from '@/components/one-time-secret-banner';
import { ProvisioningPanel } from '@/components/provisioning-badge';
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
import { isApplying, usePollWhile } from '@/hooks/use-poll-while';
import { apiPost } from '@/lib/api';
import { submitHandoff } from '@/lib/handoff';
import tenantDatabases from '@/routes/tenant-databases';
import type { TenantDatabase } from '@/types';
import type {
    AdminerSessionResponse,
    TenantDatabaseRemoteAccess,
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

    if (!generated || generated.uuid !== tenantDatabase.uuid) {
        return null;
    }

    return (
        <OneTimeSecretBanner
            title="Save this password now"
            secret={generated.password}
            inputLabel="Database password"
            copyLabel="Copy password"
        >
            This is the only time the password for{' '}
            <span className="font-mono">{generated.database_user}</span> is
            shown. It is not stored anywhere you can retrieve it again -- rotate
            the password if it is lost.
        </OneTimeSecretBanner>
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
 * Edits the addresses one extra user may connect from over the network, one
 * per line. Saving an empty list makes the user local only again.
 */
function RemoteHostsDialog({
    tenantDatabase,
    user,
    remoteAccess,
}: {
    tenantDatabase: TenantDatabase;
    user: TenantDatabaseUser;
    remoteAccess: NonNullable<TenantDatabaseRemoteAccess>;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Remote access
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Remote access for {user.username}</DialogTitle>
                <DialogDescription>
                    The IP addresses this user may connect from, one per line
                    (at most {remoteAccess.max_hosts}). Clients connect to{' '}
                    <span className="font-mono">
                        {remoteAccess.host}:{remoteAccess.port}
                    </span>{' '}
                    and must use TLS. Leave it empty to allow local connections
                    only.
                </DialogDescription>

                <Form
                    {...TenantDatabaseUserController.update.form({
                        tenantDatabase: tenantDatabase.uuid,
                        user: user.uuid,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => {
                        const error =
                            errors.remote_hosts ??
                            Object.entries(errors).find(([field]) =>
                                field.startsWith('remote_hosts.'),
                            )?.[1];

                        return (
                            <div className="space-y-4">
                                <div className="grid gap-2">
                                    <Label
                                        htmlFor={`remote-hosts-${user.uuid}`}
                                    >
                                        Allowed addresses
                                    </Label>
                                    <Textarea
                                        id={`remote-hosts-${user.uuid}`}
                                        name="remote_hosts"
                                        rows={5}
                                        defaultValue={user.remote_hosts.join(
                                            '\n',
                                        )}
                                        placeholder={
                                            '203.0.113.10\n2001:db8::10'
                                        }
                                        className="font-mono"
                                    />
                                    <InputError message={error} />
                                </div>

                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button variant="secondary">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button disabled={processing} asChild>
                                        <button type="submit">Save</button>
                                    </Button>
                                </DialogFooter>
                            </div>
                        );
                    }}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The database's extra users: each with full or read-only access, remote
 * access from listed addresses, a new password on request, and removal.
 * Changing access saves as soon as it is picked. A new or rotated password
 * appears once, in the banner above.
 */
function DatabaseUsersCard({
    tenantDatabase,
    users,
    maxUsers,
    remoteAccess,
}: {
    tenantDatabase: TenantDatabase;
    users: TenantDatabaseUser[];
    maxUsers: number;
    remoteAccess: TenantDatabaseRemoteAccess;
}) {
    return (
        <div className="space-y-4 rounded-lg border p-4">
            <Heading
                variant="small"
                title="Extra users"
                description="More users for this database, for another application or a person, each with full access or read-only access (it can read tables and views, never change them)."
            />

            <p className="text-sm text-muted-foreground">
                {remoteAccess ? (
                    <>
                        An extra user can also connect from other computers:
                        give it the addresses under Remote access. Those
                        connections go to{' '}
                        <span className="font-mono">
                            {remoteAccess.host}:{remoteAccess.port}
                        </span>{' '}
                        and must use TLS.
                    </>
                ) : (
                    'Extra users connect from this server only. Remote access needs a certificate for the server hostname; ask your provider to set it up.'
                )}
            </p>

            {users.length > 0 && (
                <ul className="divide-y rounded-md border">
                    {users.map((user) => (
                        <li
                            key={user.uuid}
                            className="flex flex-wrap items-center gap-3 p-3"
                        >
                            <div className="min-w-0 basis-full sm:flex-1 sm:basis-auto">
                                <p className="truncate font-mono text-sm">
                                    {user.username}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {user.remote_hosts.length > 0
                                        ? `Remote from ${user.remote_hosts.join(', ')}`
                                        : 'Local only'}
                                </p>
                            </div>

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

                            {remoteAccess && (
                                <RemoteHostsDialog
                                    tenantDatabase={tenantDatabase}
                                    user={user}
                                    remoteAccess={remoteAccess}
                                />
                            )}

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
    remoteAccess,
}: {
    tenantDatabase: TenantDatabase;
    adminerAvailable: boolean;
    users: TenantDatabaseUser[];
    maxUsers: number;
    remoteAccess: TenantDatabaseRemoteAccess;
}) {
    const [rotateOpen, setRotateOpen] = useState(false);

    usePollWhile(isApplying(tenantDatabase.provisioning_status), [
        'tenantDatabase',
    ]);

    return (
        <>
            <Head title={tenantDatabase.label} />

            <div className="mx-auto w-full max-w-2xl space-y-8 p-4">
                <Heading
                    level={1}
                    title={tenantDatabase.label}
                    description="Database: how to connect, its users and its password"
                />

                <ProvisioningPanel
                    status={tenantDatabase.provisioning_status}
                    reason={tenantDatabase.provisioning_error}
                    updatedAt={tenantDatabase.provisioning_updated_at}
                />

                <GeneratedPasswordBanner tenantDatabase={tenantDatabase} />

                <section
                    aria-labelledby="connect-heading"
                    className="space-y-4 rounded-lg border p-4"
                >
                    <Heading
                        variant="small"
                        id="connect-heading"
                        title="How to connect"
                        description="Enter these in your site or application's database settings."
                    />
                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {[
                            ['Host', '127.0.0.1'],
                            ['Port', '3307'],
                            ['Database name', tenantDatabase.database_name],
                            ['User', tenantDatabase.database_user ?? ''],
                        ].map(([term, value]) => (
                            <div key={term}>
                                <dt className="text-sm text-muted-foreground">
                                    {term}
                                </dt>
                                <dd className="font-mono text-sm break-all">
                                    {value}
                                </dd>
                            </div>
                        ))}
                        <div className="sm:col-span-2">
                            <dt className="text-sm text-muted-foreground">
                                Password
                            </dt>
                            <dd className="text-sm">
                                Shown once, when the database was created. If it
                                is lost, give the user a new one below.
                            </dd>
                        </div>
                    </dl>
                    <p className="text-sm text-muted-foreground">
                        127.0.0.1 works for sites on this server. To connect
                        from elsewhere, add an extra user with remote access.
                    </p>
                </section>

                <OpenAdminerCard
                    tenantDatabase={tenantDatabase}
                    adminerAvailable={adminerAvailable}
                />

                <DatabaseUsersCard
                    tenantDatabase={tenantDatabase}
                    users={users}
                    maxUsers={maxUsers}
                    remoteAccess={remoteAccess}
                />

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="New password"
                        description="Give this database's own user a new password. The old one stops working at once; what the user may do stays the same. Extra users have their own, above."
                    />

                    <Dialog open={rotateOpen} onOpenChange={setRotateOpen}>
                        <DialogTrigger asChild>
                            <Button
                                variant="outline"
                                data-test="rotate-tenant-database-password-button"
                            >
                                New password
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>
                                Give {tenantDatabase.database_user} a new
                                password?
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
                                                New password
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>

                <section
                    aria-labelledby="danger-heading"
                    className="space-y-4 rounded-lg border border-red-200 p-4 dark:border-red-900"
                >
                    <Heading
                        variant="small"
                        id="danger-heading"
                        title="Suspend or delete"
                        description="Suspending stops every user of this database connecting until you unsuspend it, and keeps the data. Deleting removes the database, all its data and all its users for good."
                    />

                    <div className="flex flex-wrap gap-2">
                        {tenantDatabase.suspended_at ? (
                            <ConfirmActionDialog
                                form={TenantDatabaseController.unsuspend.form(
                                    tenantDatabase,
                                )}
                                title={`Unsuspend ${tenantDatabase.label}?`}
                                description="Its users can connect again."
                                confirmLabel="Unsuspend"
                                confirmTestId="confirm-toggle-suspend-tenant-database-button"
                                trigger={
                                    <Button data-test="toggle-suspend-tenant-database-button">
                                        Unsuspend database
                                    </Button>
                                }
                            />
                        ) : (
                            <ConfirmActionDialog
                                form={TenantDatabaseController.suspend.form(
                                    tenantDatabase,
                                )}
                                title={`Suspend ${tenantDatabase.label}?`}
                                description="Its users cannot connect until you unsuspend it, so sites using it stop working. The data is kept."
                                confirmLabel="Suspend"
                                destructive
                                confirmTestId="confirm-toggle-suspend-tenant-database-button"
                                trigger={
                                    <Button
                                        variant="outline"
                                        data-test="toggle-suspend-tenant-database-button"
                                    >
                                        Suspend database
                                    </Button>
                                }
                            />
                        )}

                        <ConfirmActionDialog
                            form={TenantDatabaseController.destroy.form(
                                tenantDatabase,
                            )}
                            title={`Delete ${tenantDatabase.label}?`}
                            description="This cannot be undone. The database, all its data and all its users are removed from the server; take a backup first if you need them."
                            confirmLabel="Delete database"
                            destructive
                            confirmTestId="confirm-delete-tenant-database-button"
                            trigger={
                                <Button
                                    variant="destructive"
                                    data-test="delete-tenant-database-button"
                                >
                                    Delete database
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
            title: 'Databases',
            href: tenantDatabases.index(),
        },
    ],
};
