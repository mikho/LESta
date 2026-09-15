import { Form, Head } from '@inertiajs/react';
import PackageController from '@/actions/App/Http/Controllers/Packages/PackageController';
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
import packages from '@/routes/packages';
import type { Package, PackageLimitRow } from '@/types';

const resourceTypeLabels: Record<string, string> = {
    web_domains: 'Web domains',
    dns_zones: 'DNS zones',
    dns_records: 'DNS records',
    mail_domains: 'Mail domains',
    mail_accounts: 'Mailboxes',
    tenant_databases: 'Databases',
    cron_jobs: 'Cron jobs',
};

function LimitRow({ pkg, limit }: { pkg: Package; limit: PackageLimitRow }) {
    return (
        <tr className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border">
            <td className="px-4 py-2 font-medium">
                {resourceTypeLabels[limit.resource_type] ?? limit.resource_type}
            </td>
            <td className="px-4 py-2">
                {!limit.configured ? (
                    <span className="text-red-600 dark:text-red-400">
                        Blocked (not configured)
                    </span>
                ) : limit.limit_value === null ? (
                    <span className="text-green-600 dark:text-green-400">
                        Unlimited
                    </span>
                ) : (
                    <span>{limit.limit_value}</span>
                )}
            </td>
            <td className="px-4 py-2">
                <Form
                    {...PackageController.updateLimit.form([
                        pkg,
                        limit.resource_type,
                    ])}
                    options={{ preserveScroll: true }}
                    className="flex items-center gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Input
                                type="number"
                                name="limit_value"
                                min={0}
                                placeholder="Unlimited"
                                defaultValue={limit.limit_value ?? undefined}
                                className="w-32"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                Save
                            </Button>
                            <InputError message={errors.limit_value} />
                        </>
                    )}
                </Form>
            </td>
        </tr>
    );
}

export default function Edit({ package: pkg }: { package: Package }) {
    const limits = pkg.limits ?? [];

    return (
        <>
            <Head title={`Edit ${pkg.name}`} />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4">
                <Heading
                    title={pkg.name}
                    description="Update this package and its quotas"
                />

                <Form
                    {...PackageController.update.form(pkg)}
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
                                    defaultValue={pkg.name}
                                />

                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>

                                <Textarea
                                    id="description"
                                    name="description"
                                    rows={3}
                                    defaultValue={pkg.description ?? ''}
                                />

                                <InputError message={errors.description} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="is_active"
                                    name="is_active"
                                    defaultChecked={pkg.is_active}
                                />
                                <Label htmlFor="is_active">
                                    Active (selectable when creating an account)
                                </Label>
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-package-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Quotas"
                        description="A resource type with no quota set is completely blocked for accounts on this package, not unlimited -- leave the field empty and save to make it unlimited instead."
                    />

                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Resource
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Current
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Set limit
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {limits.map((limit) => (
                                    <LimitRow
                                        key={limit.resource_type}
                                        pkg={pkg}
                                        limit={limit}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete package"
                        description="Delete this package"
                    />
                    <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                        <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                            <p className="font-medium">Warning</p>
                            <p className="text-sm">
                                Only possible while no account is on this
                                package.
                            </p>
                        </div>

                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    variant="destructive"
                                    data-test="delete-package-button"
                                >
                                    Delete package
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete {pkg.name}?
                                </DialogTitle>
                                <DialogDescription>
                                    This cannot be undone.
                                </DialogDescription>

                                <Form
                                    {...PackageController.destroy.form(pkg)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <InputError
                                                message={errors.package}
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
                                                        data-test="confirm-delete-package-button"
                                                    >
                                                        Delete package
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

Edit.layout = {
    breadcrumbs: [
        {
            title: 'Packages',
            href: packages.index(),
        },
    ],
};
