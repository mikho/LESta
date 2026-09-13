import { Form, Head, Link } from '@inertiajs/react';
import AccountController from '@/actions/App/Http/Controllers/Accounts/AccountController';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import accounts from '@/routes/accounts';
import usage from '@/routes/usage';
import type { Account, AccountPackage } from '@/types';

export default function Show({
    account,
    packages,
}: {
    account: Account;
    packages: AccountPackage[];
}) {
    return (
        <>
            <Head title={account.name} />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title={account.name}
                    description="View and manage this account"
                />

                <Form
                    {...AccountController.update.form(account)}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    defaultValue={account.name}
                                />

                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="contact_email">
                                    Contact email
                                </Label>

                                <Input
                                    id="contact_email"
                                    name="contact_email"
                                    type="email"
                                    defaultValue={account.contact_email ?? ''}
                                />

                                <InputError message={errors.contact_email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="package_id">Package</Label>

                                <Select
                                    name="package_id"
                                    defaultValue={String(account.package_id)}
                                >
                                    <SelectTrigger id="package_id">
                                        <SelectValue placeholder="Select a package" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {packages.map((pkg) => (
                                            <SelectItem
                                                key={pkg.id}
                                                value={String(pkg.id)}
                                            >
                                                {pkg.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <InputError message={errors.package_id} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-account-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-y-4 rounded-lg border p-4">
                    <div className="flex items-center justify-between gap-4">
                        <Heading variant="small" title="Resources" />

                        <Link
                            href={usage.index.url({
                                query: { account: account.uuid },
                            })}
                            className="text-sm underline"
                        >
                            View usage
                        </Link>
                    </div>

                    <dl className="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt className="text-muted-foreground">
                                Web domains
                            </dt>
                            <dd>{account.web_domains_count ?? 0}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Mail domains
                            </dt>
                            <dd>{account.mail_domains_count ?? 0}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Tenant databases
                            </dt>
                            <dd>{account.tenant_databases_count ?? 0}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Cron jobs</dt>
                            <dd>{account.cron_jobs_count ?? 0}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Created</dt>
                            <dd>
                                {account.created_at
                                    ? new Date(
                                          account.created_at,
                                      ).toLocaleDateString()
                                    : 'unknown'}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div className="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="Members"
                        description="Users with a membership on this account"
                    />

                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        User
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Email
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Role
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {(!account.memberships ||
                                    account.memberships.length === 0) && (
                                    <tr>
                                        <td
                                            colSpan={3}
                                            className="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            No members yet.
                                        </td>
                                    </tr>
                                )}

                                {account.memberships?.map((membership) => (
                                    <tr
                                        key={membership.id}
                                        className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td className="px-4 py-2 font-medium">
                                            {membership.user_name ?? '—'}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {membership.user_email ?? '—'}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {membership.role_name}
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
                            account.suspended_at
                                ? 'Unsuspend account'
                                : 'Suspend account'
                        }
                        description={
                            account.suspended_at
                                ? 'Resume this account and its non-manually-suspended resources.'
                                : 'Stop this account and cascade-suspend its web, mail, database, and cron resources until it is unsuspended.'
                        }
                    />

                    <Form
                        {...(account.suspended_at
                            ? AccountController.unsuspend.form(account)
                            : AccountController.suspend.form(account))}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant={
                                    account.suspended_at ? 'default' : 'outline'
                                }
                                disabled={processing}
                                data-test="toggle-suspend-account-button"
                            >
                                {account.suspended_at ? 'Unsuspend' : 'Suspend'}
                            </Button>
                        )}
                    </Form>
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete account"
                        description="Delete this account"
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
                                    data-test="delete-account-button"
                                >
                                    Delete account
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete{' '}
                                    {account.name}?
                                </DialogTitle>
                                <DialogDescription>
                                    This deletes the account and every web
                                    domain, mail domain, tenant database, and
                                    cron job it owns.
                                </DialogDescription>

                                <Form
                                    {...AccountController.destroy.form(account)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <InputError
                                                message={errors.account}
                                            />

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
                                                        data-test="confirm-delete-account-button"
                                                    >
                                                        Delete account
                                                    </button>
                                                </Button>
                                            </DialogFooter>
                                        </>
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

Show.layout = {
    breadcrumbs: [
        {
            title: 'Accounts',
            href: accounts.index(),
        },
    ],
};
