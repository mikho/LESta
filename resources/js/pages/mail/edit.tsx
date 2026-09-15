import { Form, Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import MailAccountController from '@/actions/App/Http/Controllers/Mail/MailAccountController';
import MailDomainController from '@/actions/App/Http/Controllers/Mail/MailDomainController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import mail from '@/routes/mail';
import type { MailAccount, MailDomain } from '@/types';

function PasswordRevealDialog() {
    const [password, setPassword] = useState<string | null>(null);

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const issuedPassword = flash?.mailAccountPassword as
                string | undefined;

            if (issuedPassword) {
                setPassword(issuedPassword);
            }
        });
    }, []);

    return (
        <Dialog
            open={password !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setPassword(null);
                }
            }}
        >
            <DialogContent>
                <DialogTitle>Mailbox password</DialogTitle>
                <DialogDescription>
                    This password is shown once and cannot be recovered
                    afterwards. Copy it now.
                </DialogDescription>

                <Input readOnly value={password ?? ''} className="font-mono" />

                <DialogFooter>
                    <DialogClose asChild>
                        <Button>Done</Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AddMailAccountDialog({ mailDomain }: { mailDomain: MailDomain }) {
    const [open, setOpen] = useState(false);
    const [autoreplyEnabled, setAutoreplyEnabled] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" data-test="add-mail-account-button">
                    Add mailbox
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Add a mailbox</DialogTitle>
                <DialogDescription>
                    Add a new mailbox to {mailDomain.domain}.
                </DialogDescription>

                <Form
                    {...MailAccountController.store.form(mailDomain)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="local_part">Mailbox name</Label>

                                <div className="flex items-center gap-2">
                                    <Input
                                        id="local_part"
                                        name="local_part"
                                        required
                                        placeholder="info"
                                    />
                                    <span className="text-sm text-muted-foreground">
                                        @{mailDomain.domain}
                                    </span>
                                </div>

                                <InputError message={errors.local_part} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="quota_mb">
                                    Quota (MB, optional)
                                </Label>

                                <Input
                                    id="quota_mb"
                                    name="quota_mb"
                                    type="number"
                                />

                                <InputError message={errors.quota_mb} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="forward_to">
                                    Forward to (optional)
                                </Label>

                                <Input
                                    id="forward_to"
                                    name="forward_to"
                                    type="email"
                                />

                                <InputError message={errors.forward_to} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="forward_only"
                                    name="forward_only"
                                />
                                <Label htmlFor="forward_only">
                                    Forward only, don't keep a copy
                                </Label>
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="autoreply_enabled"
                                    name="autoreply_enabled"
                                    checked={autoreplyEnabled}
                                    onCheckedChange={(checked) =>
                                        setAutoreplyEnabled(checked === true)
                                    }
                                />
                                <Label htmlFor="autoreply_enabled">
                                    Send an autoreply
                                </Label>
                            </div>

                            {autoreplyEnabled && (
                                <div className="grid gap-2">
                                    <Label htmlFor="autoreply_message">
                                        Autoreply message
                                    </Label>

                                    <Textarea
                                        id="autoreply_message"
                                        name="autoreply_message"
                                        rows={3}
                                    />

                                    <InputError
                                        message={errors.autoreply_message}
                                    />
                                </div>
                            )}

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    disabled={processing}
                                    data-test="create-mail-account-button"
                                >
                                    Add mailbox
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function EditMailAccountDialog({
    mailDomain,
    account,
}: {
    mailDomain: MailDomain;
    account: MailAccount;
}) {
    const [open, setOpen] = useState(false);
    const [autoreplyEnabled, setAutoreplyEnabled] = useState(
        account.autoreply_enabled,
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Edit
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Edit {account.local_part}</DialogTitle>
                <DialogDescription>
                    Update this mailbox on {mailDomain.domain}.
                </DialogDescription>

                <Form
                    {...MailAccountController.update.form([
                        mailDomain,
                        account,
                    ])}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="edit-quota_mb">
                                    Quota (MB, optional)
                                </Label>

                                <Input
                                    id="edit-quota_mb"
                                    name="quota_mb"
                                    type="number"
                                    defaultValue={account.quota_mb ?? undefined}
                                />

                                <InputError message={errors.quota_mb} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="edit-forward_to">
                                    Forward to (optional)
                                </Label>

                                <Input
                                    id="edit-forward_to"
                                    name="forward_to"
                                    type="email"
                                    defaultValue={
                                        account.forward_to ?? undefined
                                    }
                                />

                                <InputError message={errors.forward_to} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="edit-forward_only"
                                    name="forward_only"
                                    defaultChecked={account.forward_only}
                                />
                                <Label htmlFor="edit-forward_only">
                                    Forward only, don't keep a copy
                                </Label>
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="edit-autoreply_enabled"
                                    name="autoreply_enabled"
                                    checked={autoreplyEnabled}
                                    onCheckedChange={(checked) =>
                                        setAutoreplyEnabled(checked === true)
                                    }
                                />
                                <Label htmlFor="edit-autoreply_enabled">
                                    Send an autoreply
                                </Label>
                            </div>

                            {autoreplyEnabled && (
                                <div className="grid gap-2">
                                    <Label htmlFor="edit-autoreply_message">
                                        Autoreply message
                                    </Label>

                                    <Textarea
                                        id="edit-autoreply_message"
                                        name="autoreply_message"
                                        rows={3}
                                        defaultValue={
                                            account.autoreply_message ??
                                            undefined
                                        }
                                    />

                                    <InputError
                                        message={errors.autoreply_message}
                                    />
                                </div>
                            )}

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    disabled={processing}
                                    data-test="update-mail-account-button"
                                >
                                    Save
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function RotatePasswordDialog({
    mailDomain,
    account,
}: {
    mailDomain: MailDomain;
    account: MailAccount;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Rotate password
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    Rotate the password for {account.local_part}?
                </DialogTitle>
                <DialogDescription>
                    The current password stops working immediately. The new one
                    is shown once and cannot be recovered afterwards.
                </DialogDescription>

                <Form
                    {...MailAccountController.rotatePassword.form([
                        mailDomain,
                        account,
                    ])}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>

                            <Button disabled={processing} asChild>
                                <button type="submit">Rotate password</button>
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ToggleSuspendMailAccountDialog({
    mailDomain,
    account,
}: {
    mailDomain: MailDomain;
    account: MailAccount;
}) {
    const [open, setOpen] = useState(false);

    if (account.suspended_at) {
        return (
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <Button variant="outline" size="sm">
                        Unsuspend
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Unsuspend {account.local_part}?</DialogTitle>
                    <DialogDescription>
                        The mailbox will resume sending and receiving mail.
                    </DialogDescription>

                    <Form
                        {...MailAccountController.unsuspend.form([
                            mailDomain,
                            account,
                        ])}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setOpen(false)}
                    >
                        {({ processing }) => (
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button disabled={processing} asChild>
                                    <button type="submit">Unsuspend</button>
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Suspend
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Suspend {account.local_part}?</DialogTitle>
                <DialogDescription>
                    The mailbox will stop sending and receiving mail until it is
                    unsuspended.
                </DialogDescription>

                <Form
                    {...MailAccountController.suspend.form([
                        mailDomain,
                        account,
                    ])}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>

                            <Button
                                variant="destructive"
                                disabled={processing}
                                asChild
                            >
                                <button type="submit">Suspend</button>
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function Edit({ mailDomain }: { mailDomain: MailDomain }) {
    const accounts = mailDomain.accounts ?? [];
    const [dkimEnabled, setDkimEnabled] = useState(mailDomain.dkim_enabled);

    return (
        <>
            <Head title={`Edit ${mailDomain.domain}`} />

            <PasswordRevealDialog />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4">
                <Heading
                    title={mailDomain.domain}
                    description="Update this mail domain's configuration"
                />

                <Form
                    {...MailDomainController.update.form(mailDomain)}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="catchall_email">
                                    Catch-all email
                                </Label>

                                <Input
                                    id="catchall_email"
                                    name="catchall_email"
                                    type="email"
                                    defaultValue={
                                        mailDomain.catchall_email ?? undefined
                                    }
                                />

                                <InputError message={errors.catchall_email} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="antivirus_enabled"
                                    name="antivirus_enabled"
                                    defaultChecked={
                                        mailDomain.antivirus_enabled
                                    }
                                />
                                <Label htmlFor="antivirus_enabled">
                                    Scan incoming mail for viruses
                                </Label>
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="antispam_enabled"
                                    name="antispam_enabled"
                                    defaultChecked={mailDomain.antispam_enabled}
                                />
                                <Label htmlFor="antispam_enabled">
                                    Scan incoming mail for spam
                                </Label>
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center space-x-3">
                                    <Checkbox
                                        id="dkim_enabled"
                                        name="dkim_enabled"
                                        checked={dkimEnabled}
                                        onCheckedChange={(checked) =>
                                            setDkimEnabled(checked === true)
                                        }
                                    />
                                    <Label htmlFor="dkim_enabled">
                                        Sign outgoing mail with DKIM
                                    </Label>
                                </div>

                                {mailDomain.dkim_enabled &&
                                    mailDomain.dkim_selector && (
                                        <p className="text-sm text-muted-foreground">
                                            Active selector:{' '}
                                            {mailDomain.dkim_selector}
                                            {mailDomain.dkim_selector_activated_at
                                                ? ` (since ${new Date(mailDomain.dkim_selector_activated_at).toLocaleDateString()})`
                                                : ''}
                                            . Rotates automatically.
                                        </p>
                                    )}
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-mail-domain-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-y-4">
                    <div className="flex items-center justify-between gap-4">
                        <Heading variant="small" title="Mailboxes" />

                        <AddMailAccountDialog mailDomain={mailDomain} />
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Mailbox
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Quota
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Forwarding
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Suspension
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {accounts.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            No mailboxes yet.
                                        </td>
                                    </tr>
                                )}

                                {accounts.map((account) => (
                                    <tr
                                        key={account.uuid}
                                        className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td className="px-4 py-2 font-medium">
                                            {account.local_part}@
                                            {mailDomain.domain}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {account.quota_mb
                                                ? `${account.quota_mb} MB`
                                                : 'Unlimited'}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {account.forward_to
                                                ? `${account.forward_to}${account.forward_only ? ' (forward only)' : ''}`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {account.suspended_at ? (
                                                <span className="text-red-600 dark:text-red-400">
                                                    Suspended
                                                    {account.suspension_source ===
                                                    'cascade'
                                                        ? ' (domain)'
                                                        : ''}
                                                </span>
                                            ) : (
                                                <span className="text-green-600 dark:text-green-400">
                                                    Active
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <EditMailAccountDialog
                                                    mailDomain={mailDomain}
                                                    account={account}
                                                />

                                                <RotatePasswordDialog
                                                    mailDomain={mailDomain}
                                                    account={account}
                                                />

                                                <ToggleSuspendMailAccountDialog
                                                    mailDomain={mailDomain}
                                                    account={account}
                                                />

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
                                                            Delete{' '}
                                                            {account.local_part}
                                                            ?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            This cannot be
                                                            undone.
                                                        </DialogDescription>

                                                        <Form
                                                            {...MailAccountController.destroy.form(
                                                                [
                                                                    mailDomain,
                                                                    account,
                                                                ],
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
                </div>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title={
                            mailDomain.suspended_at
                                ? 'Unsuspend domain'
                                : 'Suspend domain'
                        }
                        description={
                            mailDomain.suspended_at
                                ? 'Resume sending and receiving mail for this domain.'
                                : 'Stop sending and receiving mail for this domain until it is unsuspended.'
                        }
                    />

                    <Form
                        {...(mailDomain.suspended_at
                            ? MailDomainController.unsuspend.form(mailDomain)
                            : MailDomainController.suspend.form(mailDomain))}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant={
                                    mailDomain.suspended_at
                                        ? 'default'
                                        : 'outline'
                                }
                                disabled={processing}
                                data-test="toggle-suspend-mail-domain-button"
                            >
                                {mailDomain.suspended_at
                                    ? 'Unsuspend'
                                    : 'Suspend'}
                            </Button>
                        )}
                    </Form>
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete domain"
                        description="Delete this domain and all of its mailboxes"
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
                                    data-test="delete-mail-domain-button"
                                >
                                    Delete domain
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete{' '}
                                    {mailDomain.domain}?
                                </DialogTitle>
                                <DialogDescription>
                                    Once this domain is deleted, all of its
                                    mailboxes and provisioning state will also
                                    be permanently deleted.
                                </DialogDescription>

                                <Form
                                    {...MailDomainController.destroy.form(
                                        mailDomain,
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
                                                    data-test="confirm-delete-mail-domain-button"
                                                >
                                                    Delete domain
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
            title: 'Mail',
            href: mail.index(),
        },
    ],
};
