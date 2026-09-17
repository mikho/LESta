import { Form, Head } from '@inertiajs/react';
import RoleController from '@/actions/App/Http/Controllers/Roles/RoleController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/roles';

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

export default function Create({
    permissionCatalog,
}: {
    permissionCatalog: string[];
}) {
    const groups = groupPermissionsByResource(permissionCatalog);

    return (
        <>
            <Head title="Create role" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Create role"
                    description="A custom platform-scope role holding exactly the permissions you grant it"
                />

                <Form {...RoleController.store.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    placeholder="billing_admin"
                                />

                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>

                                <Textarea
                                    id="description"
                                    name="description"
                                    rows={3}
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
                                    data-test="create-role-button"
                                >
                                    Create role
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Create.layout = {
    breadcrumbs: [
        {
            title: 'Roles',
            href: index(),
        },
        {
            title: 'Create',
            href: RoleController.create(),
        },
    ],
};
