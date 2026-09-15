import { Head, Link } from '@inertiajs/react';
import PackageController from '@/actions/App/Http/Controllers/Packages/PackageController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import packages from '@/routes/packages';
import type { Package } from '@/types';

export default function Index({
    packages: allPackages,
}: {
    packages: Package[];
}) {
    return (
        <>
            <Head title="Packages" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="Packages"
                        description="Plans that define what an account is allowed to have"
                    />

                    <Button asChild>
                        <Link href={PackageController.create()}>
                            Create package
                        </Link>
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                            <tr>
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">
                                    Description
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Accounts
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {allPackages.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        No packages yet.
                                    </td>
                                </tr>
                            )}

                            {allPackages.map((pkg) => (
                                <tr
                                    key={pkg.uuid}
                                    className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                >
                                    <td className="px-4 py-2 font-medium">
                                        {pkg.name}
                                    </td>
                                    <td className="max-w-xs truncate px-4 py-2 text-muted-foreground">
                                        {pkg.description ?? '—'}
                                    </td>
                                    <td className="px-4 py-2">
                                        {pkg.is_active ? (
                                            <span className="text-green-600 dark:text-green-400">
                                                Active
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Inactive
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {pkg.accounts_count ?? 0}
                                    </td>
                                    <td className="px-4 py-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={PackageController.edit(
                                                    pkg,
                                                )}
                                            >
                                                Manage
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

Index.layout = {
    breadcrumbs: [
        {
            title: 'Packages',
            href: packages.index(),
        },
    ],
};
