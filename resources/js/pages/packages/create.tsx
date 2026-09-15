import { Form, Head } from '@inertiajs/react';
import PackageController from '@/actions/App/Http/Controllers/Packages/PackageController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/packages';

export default function Create() {
    return (
        <>
            <Head title="Create package" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Create package"
                    description="A package's quotas can be set once it exists"
                />

                <Form {...PackageController.store.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    placeholder="Starter"
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

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="is_active"
                                    name="is_active"
                                    defaultChecked
                                />
                                <Label htmlFor="is_active">
                                    Active (selectable when creating an account)
                                </Label>
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="create-package-button"
                                >
                                    Create package
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
            title: 'Packages',
            href: index(),
        },
        {
            title: 'Create',
            href: PackageController.create(),
        },
    ],
};
