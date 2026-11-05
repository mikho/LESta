import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({ status }: { status?: string }) {
    const { auth } = usePage<PageProps>().props;
    const [email, setEmail] = useState(auth.user.email);
    const emailChanged = email !== auth.user.email;

    return (
        <>
            <Head title="Profile settings" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile"
                    description="Update your name and email address"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    value={email}
                                    onChange={(event) =>
                                        setEmail(event.target.value)
                                    }
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Email address"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {emailChanged && (
                                <div className="grid gap-2">
                                    <Label htmlFor="current_password">
                                        Current password
                                    </Label>

                                    <Input
                                        id="current_password"
                                        type="password"
                                        className="mt-1 block w-full"
                                        name="current_password"
                                        required
                                        autoComplete="current-password"
                                    />

                                    <p className="text-sm text-muted-foreground">
                                        Changing your email address needs your
                                        password. We then send a link to the new
                                        address to confirm it.
                                    </p>

                                    <InputError
                                        className="mt-2"
                                        message={errors.current_password}
                                    />
                                </div>
                            )}

                            {auth.user.email_verified_at === null && (
                                <div>
                                    <p className="-mt-4 text-sm text-muted-foreground">
                                        Your email address is unverified.{' '}
                                        <Link
                                            href={send()}
                                            as="button"
                                            className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                        >
                                            Click here to re-send the
                                            verification email.
                                        </Link>
                                    </p>

                                    {status === 'verification-link-sent' && (
                                        <div className="mt-2 text-sm font-medium text-green-600">
                                            A new verification link has been
                                            sent to your email address.
                                        </div>
                                    )}
                                </div>
                            )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Download your data"
                    description="A JSON file with the personal data we hold about you"
                />
                <Button variant="outline" asChild>
                    <a
                        href={ProfileController.export.url()}
                        data-test="export-data-link"
                    >
                        Download my data
                    </a>
                </Button>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
