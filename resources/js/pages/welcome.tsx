import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Welcome" />

            <div className="flex min-h-svh flex-col items-center justify-center gap-8 bg-background p-6 text-foreground">
                <div className="flex flex-col items-center gap-4">
                    <div className="flex h-14 w-14 items-center justify-center rounded-xl bg-sidebar-primary text-sidebar-primary-foreground">
                        <AppLogoIcon className="size-8 fill-current" />
                    </div>

                    <div className="space-y-1 text-center">
                        <h1 className="text-2xl font-semibold">{name}</h1>
                        <p className="text-sm text-muted-foreground">
                            Web hosting control panel
                        </p>
                    </div>
                </div>

                <Button asChild size="lg">
                    <Link href={auth.user ? dashboard() : login()}>
                        {auth.user ? 'Go to dashboard' : 'Log in'}
                    </Link>
                </Button>
            </div>
        </>
    );
}
