import { Form, Head } from '@inertiajs/react';
import AccountController from '@/actions/App/Http/Controllers/Accounts/AccountController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import type { AccountPackage } from '@/types';

export default function Create({ packages }: { packages: AccountPackage[] }) {
    return (
        <>
            <Head title="Create account" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Create account"
                    description="Set up a new hosting account and its owner"
                />

                <Form {...AccountController.store.form()} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Account name</Label>

                                <Input id="name" name="name" required />

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
                                />

                                <InputError message={errors.contact_email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="package_id">Package</Label>

                                <Select name="package_id">
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

                            <div className="space-y-4 border-t pt-6">
                                <Heading
                                    variant="small"
                                    title="Owner"
                                    description="The user who will own this account. If no user exists with this email, one is created and sent a link to set their own password."
                                />

                                <div className="grid gap-2">
                                    <Label htmlFor="owner_name">
                                        Owner name
                                    </Label>

                                    <Input
                                        id="owner_name"
                                        name="owner_name"
                                        required
                                    />

                                    <InputError message={errors.owner_name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="owner_email">
                                        Owner email
                                    </Label>

                                    <Input
                                        id="owner_email"
                                        name="owner_email"
                                        type="email"
                                        required
                                    />

                                    <InputError message={errors.owner_email} />
                                </div>
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="create-account-button"
                                >
                                    Create account
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
            title: 'Accounts',
            href: accounts.index(),
        },
        {
            title: 'Create',
            href: accounts.create(),
        },
    ],
};
