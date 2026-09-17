import { Head, Link } from '@inertiajs/react';
import RoleController from '@/actions/App/Http/Controllers/Roles/RoleController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import roles from '@/routes/roles';
import type { Role } from '@/types';

export default function Index({ roles: allRoles }: { roles: Role[] }) {
    return (
        <>
            <Head title="Roles" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="Roles"
                        description="Custom platform-scope roles, each holding exactly the permissions you grant it"
                    />

                    <Button asChild>
                        <Link href={RoleController.create()}>Create role</Link>
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
                                <th className="px-4 py-2 font-medium">Users</th>
                                <th className="px-4 py-2 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {allRoles.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        No custom roles yet.
                                    </td>
                                </tr>
                            )}

                            {allRoles.map((role) => (
                                <tr
                                    key={role.id}
                                    className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                >
                                    <td className="px-4 py-2 font-medium">
                                        {role.name}
                                    </td>
                                    <td className="max-w-xs truncate px-4 py-2 text-muted-foreground">
                                        {role.description ?? '—'}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {role.memberships_count ?? 0}
                                    </td>
                                    <td className="px-4 py-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={RoleController.edit(role)}
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
            title: 'Roles',
            href: roles.index(),
        },
    ],
};
