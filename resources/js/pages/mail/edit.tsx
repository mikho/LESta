import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import MailAccountController from '@/actions/App/Http/Controllers/Mail/MailAccountController';
import MailDomainController from '@/actions/App/Http/Controllers/Mail/MailDomainController';
import { ConfirmActionDialog } from '@/components/confirm-action-dialog';
import { FormCheckbox } from '@/components/form-checkbox';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoreActionsMenu } from '@/components/own-resource-list';
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
import mail from '@/routes/mail';
import type { MailAccount, MailDomain } from '@/types';
import type { WebmailSessionResponse } from '@/types/mail';

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
                                <FormCheckbox
                                    id="forward_only"
                                    name="forward_only"
                                />
                                <Label htmlFor="forward_only">
                                    Forward only, don't keep a copy
                                </Label>
                            </div>

                            <div className="flex items-center space-x-3">
                                <FormCheckbox
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

/**
 * A list field's own error, or its first entry's ("spam_allow.2" for the third line).
 */
function firstError(
    errors: Record<string, string | undefined>,
    field: string,
): string | undefined {
    return (
        errors[field] ??
        Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
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
                <Button
                    variant="outline"
                    size="sm"
                    aria-label={`Edit ${account.local_part}`}
                    className="pointer-coarse:h-11"
                >
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
                                <FormCheckbox
                                    id="edit-forward_only"
                                    name="forward_only"
                                    defaultChecked={account.forward_only}
                                />
                                <Label htmlFor="edit-forward_only">
                                    Forward only, don't keep a copy
                                </Label>
                            </div>

                            <div className="flex items-center space-x-3">
                                <FormCheckbox
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

                            <fieldset className="grid gap-3 rounded-lg border p-3">
                                <legend className="px-1 text-sm font-medium">
                                    Spam
                                </legend>

                                {!mailDomain.antispam_enabled && (
                                    <p className="text-xs text-muted-foreground">
                                        Spam checking is off for this domain, so
                                        only the block list applies.
                                    </p>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="edit-spam_sensitivity">
                                        Move spam to the Junk folder
                                    </Label>
                                    <select
                                        id="edit-spam_sensitivity"
                                        name="spam_sensitivity"
                                        defaultValue={account.spam_sensitivity}
                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option value="off">Never</option>
                                        <option value="low">
                                            Only obvious spam
                                        </option>
                                        <option value="medium">
                                            Likely spam (recommended)
                                        </option>
                                        <option value="high">
                                            Anything suspicious
                                        </option>
                                    </select>
                                    <InputError
                                        message={errors.spam_sensitivity}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="edit-spam_allow">
                                        Always accept from
                                    </Label>
                                    <Textarea
                                        id="edit-spam_allow"
                                        name="spam_allow"
                                        rows={3}
                                        placeholder={
                                            'friend@example.com\npartner.example'
                                        }
                                        defaultValue={account.spam_allow.join(
                                            '\n',
                                        )}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        One address or domain per line. Never
                                        moved to Junk.
                                    </p>
                                    <InputError
                                        message={firstError(
                                            errors,
                                            'spam_allow',
                                        )}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="edit-spam_block">
                                        Block
                                    </Label>
                                    <Textarea
                                        id="edit-spam_block"
                                        name="spam_block"
                                        rows={3}
                                        placeholder="spammer.example"
                                        defaultValue={account.spam_block.join(
                                            '\n',
                                        )}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        One address or domain per line. Their
                                        mail is deleted without delivery.
                                    </p>
                                    <InputError
                                        message={firstError(
                                            errors,
                                            'spam_block',
                                        )}
                                    />
                                </div>
                            </fieldset>

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

/**
 * Opens webmail for this mailbox in a new tab, leaving this page as it is.
 * MailAccountController::openWebmail() is a plain JSON endpoint, not an Inertia visit (its
 * redirect target, the node's own address, is outside this app's own Inertia protocol), so
 * this uses `apiPost` and then points the new tab at the returned URL. The tab is opened
 * synchronously in the click handler, before the request: opened after an `await`, browsers
 * treat it as an unrequested popup and block it. If it is blocked anyway, this falls back to
 * navigating the current tab.
 */
function OpenWebmailButton({
    mailDomain,
    account,
    webmailAvailable,
}: {
    mailDomain: MailDomain;
    account: MailAccount;
    webmailAvailable: boolean;
}) {
    const [opening, setOpening] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const openWebmail = async () => {
        setOpening(true);
        setError(null);

        const webmailTab = window.open('', '_blank');

        try {
            const handoff = await apiPost<WebmailSessionResponse>(
                MailAccountController.openWebmail([mailDomain, account]).url,
            );

            submitHandoff(handoff, webmailTab);
        } catch {
            webmailTab?.close();
            setError('Could not prepare a webmail session. Try again.');
        } finally {
            setOpening(false);
        }
    };

    return (
        <>
            <Button
                variant="outline"
                size="sm"
                disabled={
                    !webmailAvailable ||
                    account.suspended_at !== null ||
                    opening
                }
                onClick={openWebmail}
                data-test="open-webmail-button"
            >
                {opening ? 'Preparing…' : 'Open webmail'}
            </Button>

            {error && (
                <span className="text-xs text-red-600 dark:text-red-400">
                    {error}
                </span>
            )}
        </>
    );
}

export default function Edit({
    mailDomain,
    webmailAvailable,
}: {
    mailDomain: MailDomain;
    webmailAvailable: boolean;
}) {
    const accounts = mailDomain.accounts ?? [];
    const [dkimEnabled, setDkimEnabled] = useState(mailDomain.dkim_enabled);

    usePollWhile(isApplying(mailDomain.provisioning_status), ['mailDomain']);

    return (
        <>
            <Head title={mailDomain.domain} />

            <PasswordRevealDialog />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        level={1}
                        title={mailDomain.domain}
                        description="Mail domain: its mailboxes and mail settings"
                    />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/mail/${mailDomain.uuid}/lists`}>
                            Mailing lists
                        </Link>
                    </Button>
                </div>

                <ProvisioningPanel
                    status={mailDomain.provisioning_status}
                    reason={mailDomain.provisioning_error}
                    updatedAt={mailDomain.provisioning_updated_at}
                />

                <section
                    aria-labelledby="mailboxes-heading"
                    className="space-y-4"
                >
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <Heading
                            variant="small"
                            id="mailboxes-heading"
                            title="Mailboxes"
                            description="Each mailbox is an address with its own password, such as info@ or anna@."
                        />

                        <AddMailAccountDialog mailDomain={mailDomain} />
                    </div>

                    {!webmailAvailable && accounts.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            Webmail is not available on this server yet; use a
                            mail app instead, or ask your provider to enable it.
                        </p>
                    )}

                    {accounts.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            No mailboxes yet. Add one to start receiving mail at
                            this domain.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table className="w-full text-left text-sm">
                                <caption className="sr-only">
                                    Mailboxes on {mailDomain.domain}
                                </caption>
                                <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="px-4 py-2 font-medium"
                                        >
                                            Address
                                        </th>
                                        <th
                                            scope="col"
                                            className="hidden px-4 py-2 font-medium md:table-cell"
                                        >
                                            Size limit
                                        </th>
                                        <th
                                            scope="col"
                                            className="hidden px-4 py-2 font-medium md:table-cell"
                                        >
                                            Forwards to
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-2 font-medium"
                                        >
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-2">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {accounts.map((account) => {
                                        const address = `${account.local_part}@${mailDomain.domain}`;

                                        return (
                                            <tr
                                                key={account.uuid}
                                                className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                            >
                                                <th
                                                    scope="row"
                                                    className="px-4 py-3 text-left font-medium [overflow-wrap:anywhere]"
                                                >
                                                    {address}
                                                </th>
                                                <td className="hidden px-4 py-3 whitespace-nowrap text-muted-foreground md:table-cell">
                                                    {account.quota_mb
                                                        ? `${account.quota_mb} MB`
                                                        : 'No limit'}
                                                </td>
                                                <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                    {account.forward_to
                                                        ? `${account.forward_to}${account.forward_only ? ' (not kept here)' : ''}`
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {account.suspended_at ? (
                                                        <span className="text-red-700 dark:text-red-400">
                                                            {account.suspension_source ===
                                                            'cascade'
                                                                ? 'Suspended with the domain'
                                                                : 'Suspended'}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Active
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {webmailAvailable && (
                                                            <OpenWebmailButton
                                                                mailDomain={
                                                                    mailDomain
                                                                }
                                                                account={
                                                                    account
                                                                }
                                                                webmailAvailable={
                                                                    webmailAvailable
                                                                }
                                                            />
                                                        )}
                                                        <EditMailAccountDialog
                                                            mailDomain={
                                                                mailDomain
                                                            }
                                                            account={account}
                                                        />
                                                        <MoreActionsMenu
                                                            name={address}
                                                            actions={[
                                                                {
                                                                    label: 'New password',
                                                                    confirm: {
                                                                        form: MailAccountController.rotatePassword.form(
                                                                            [
                                                                                mailDomain,
                                                                                account,
                                                                            ],
                                                                        ),
                                                                        title: `Give ${address} a new password?`,
                                                                        description:
                                                                            'The current password stops working at once, so mail apps using it must be given the new one. It is shown once.',
                                                                        confirmLabel:
                                                                            'New password',
                                                                    },
                                                                },
                                                                account.suspended_at
                                                                    ? {
                                                                          label: 'Unsuspend',
                                                                          confirm:
                                                                              {
                                                                                  form: MailAccountController.unsuspend.form(
                                                                                      [
                                                                                          mailDomain,
                                                                                          account,
                                                                                      ],
                                                                                  ),
                                                                                  title: `Unsuspend ${address}?`,
                                                                                  description:
                                                                                      'The mailbox can send and receive mail again.',
                                                                                  confirmLabel:
                                                                                      'Unsuspend',
                                                                              },
                                                                      }
                                                                    : {
                                                                          label: 'Suspend',
                                                                          confirm:
                                                                              {
                                                                                  form: MailAccountController.suspend.form(
                                                                                      [
                                                                                          mailDomain,
                                                                                          account,
                                                                                      ],
                                                                                  ),
                                                                                  title: `Suspend ${address}?`,
                                                                                  description:
                                                                                      'The mailbox cannot send or receive mail until you unsuspend it. Its mail is kept.',
                                                                                  confirmLabel:
                                                                                      'Suspend',
                                                                                  destructive: true,
                                                                              },
                                                                      },
                                                                {
                                                                    label: 'Delete',
                                                                    confirm: {
                                                                        form: MailAccountController.destroy.form(
                                                                            [
                                                                                mailDomain,
                                                                                account,
                                                                            ],
                                                                        ),
                                                                        title: `Delete ${address}?`,
                                                                        description:
                                                                            'This cannot be undone. The mailbox and all its mail are deleted from the server.',
                                                                        confirmLabel:
                                                                            'Delete mailbox',
                                                                        destructive: true,
                                                                    },
                                                                },
                                                            ]}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section
                    aria-labelledby="mail-settings-heading"
                    className="space-y-4 rounded-lg border p-4"
                >
                    <Heading
                        variant="small"
                        id="mail-settings-heading"
                        title="Mail settings"
                        description="How mail for the whole domain is handled."
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
                                        Catch-all mailbox
                                    </Label>

                                    <Input
                                        id="catchall_email"
                                        name="catchall_email"
                                        type="email"
                                        defaultValue={
                                            mailDomain.catchall_email ??
                                            undefined
                                        }
                                    />

                                    <p className="text-sm text-muted-foreground">
                                        An existing mailbox on this domain that
                                        receives mail sent to any address
                                        without its own mailbox. Leave empty to
                                        reject it.
                                    </p>

                                    <InputError
                                        message={errors.catchall_email}
                                    />
                                </div>

                                <div className="flex items-center space-x-3">
                                    <FormCheckbox
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
                                    <FormCheckbox
                                        id="antispam_enabled"
                                        name="antispam_enabled"
                                        defaultChecked={
                                            mailDomain.antispam_enabled
                                        }
                                    />
                                    <Label htmlFor="antispam_enabled">
                                        Scan incoming mail for spam
                                    </Label>
                                </div>

                                <div className="grid gap-2">
                                    <div className="flex items-center space-x-3">
                                        <FormCheckbox
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
                </section>

                <section
                    aria-labelledby="danger-heading"
                    className="space-y-4 rounded-lg border border-red-200 p-4 dark:border-red-900"
                >
                    <Heading
                        variant="small"
                        id="danger-heading"
                        title="Suspend or delete"
                        description="Suspending refuses mail for the whole domain until you unsuspend it and keeps the mail already here. Deleting removes the domain, its mailboxes and all their mail for good."
                    />

                    <div className="flex flex-wrap gap-2">
                        {mailDomain.suspended_at ? (
                            <ConfirmActionDialog
                                form={MailDomainController.unsuspend.form(
                                    mailDomain,
                                )}
                                title={`Unsuspend ${mailDomain.domain}?`}
                                description="Mail for this domain is accepted again."
                                confirmLabel="Unsuspend"
                                confirmTestId="confirm-toggle-suspend-mail-domain-button"
                                trigger={
                                    <Button data-test="toggle-suspend-mail-domain-button">
                                        Unsuspend mail domain
                                    </Button>
                                }
                            />
                        ) : (
                            <ConfirmActionDialog
                                form={MailDomainController.suspend.form(
                                    mailDomain,
                                )}
                                title={`Suspend ${mailDomain.domain}?`}
                                description="Mail for this domain is refused until you unsuspend it. Mail already in the mailboxes is kept."
                                confirmLabel="Suspend"
                                destructive
                                confirmTestId="confirm-toggle-suspend-mail-domain-button"
                                trigger={
                                    <Button
                                        variant="outline"
                                        data-test="toggle-suspend-mail-domain-button"
                                    >
                                        Suspend mail domain
                                    </Button>
                                }
                            />
                        )}

                        <ConfirmActionDialog
                            form={MailDomainController.destroy.form(mailDomain)}
                            title={`Delete ${mailDomain.domain}?`}
                            description="This cannot be undone. The domain, its mailboxes and all their mail are deleted from the server; take a backup first if you need them."
                            confirmLabel="Delete mail domain"
                            destructive
                            confirmTestId="confirm-delete-mail-domain-button"
                            trigger={
                                <Button
                                    variant="destructive"
                                    data-test="delete-mail-domain-button"
                                >
                                    Delete mail domain
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
            title: 'Mail',
            href: mail.index(),
        },
    ],
};
