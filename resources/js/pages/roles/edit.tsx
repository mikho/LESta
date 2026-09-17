import { Form, Head } from '@inertiajs/react';
import RoleController from '@/actions/App/Http/Controllers/Roles/RoleController';
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
import roles from '@/routes/roles';
import type { Role } from '@/types';

function groupPermissionsByResource(
    permissionCatalog: string[],
): Record<string, string[]> {
    const groups: Record<string, string[]> = {};

    for (const permission of permissionCatalog) {
        const [resource] = permission.split('.');
        groups[resource] ??= [];
        groups[resource].push(permission);
    }

    return groups;
}

export default function Edit({
    role,
    permissionCatalog,
}: {
    role: Role;
    permissionCatalog: string[];
}) {
    const groups = groupPermissionsByResource(permissionCatalog);
    const currentPermissions = new Set(role.permissions ?? []);

    return (
        <>
            <Head title={`Edit ${role.name}`} />

            <div className="mx-auto w-full max-w-2xl space-y-8 p-4">
                <Heading
                    title={role.name}
                    description="Update this role's name, description, and permissions"
                />

                <Form
                    {...RoleController.update.form(role)}
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
                                    defaultValue={role.name}
                                />

                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>

                                <Textarea
                                    id="description"
                                    name="description"
                                    rows={3}
                                    defaultValue={role.description ?? ''}
                                />

                                <InputError message={errors.description} />
                            </div>

                            <div className="grid gap-4">
                                <Label>Permissions</Label>

                                {Object.entries(groups).map(
                                    ([resource, permissions]) => (
                                        <div
                                            key={resource}
                                            className="space-y-2 rounded-lg border p-3"
                                        >
                                            <p className="text-sm font-medium capitalize">
                                                {resource}
                                            </p>

                                            <div className="grid gap-2">
                                                {permissions.map(
                                                    (permission) => (
                                                        <div
                                                            key={permission}
                                                            className="flex items-center space-x-3"
                                                        >
                                                            <Checkbox
                                                                id={`permission-${permission}`}
                                                                name="permissions[]"
                                                                value={
                                                                    permission
                                                                }
                                                                defaultChecked={currentPermissions.has(
                                                                    permission,
                                                                )}
                                                                data-test={`permission-checkbox-${permission}`}
                                                            />
                                                            <Label
                                                                htmlFor={`permission-${permission}`}
                                                                className="font-mono text-xs font-normal"
                                                            >
                                                                {permission}
                                                            </Label>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    ),
                                )}

                                <InputError message={errors.permissions} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-role-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete role"
                        description="Delete this role"
                    />
                    <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                        <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                            <p className="font-medium">Warning</p>
                            <p className="text-sm">
                                Only possible while no user holds this role.
                            </p>
                        </div>

                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    variant="destructive"
                                    data-test="delete-role-button"
                                >
                                    Delete role
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete {role.name}?
                                </DialogTitle>
                                <DialogDescription>
                                    This cannot be undone.
                                </DialogDescription>

                                <Form
                                    {...RoleController.destroy.form(role)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <InputError message={errors.role} />

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
                                                        data-test="confirm-delete-role-button"
                                                    >
                                                        Delete role
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
            title: 'Roles',
            href: roles.index(),
        },
    ],
};
