import { Link, usePage } from '@inertiajs/react';
import { cookies, privacy, terms } from '@/routes/legal';

export function LegalFooter() {
    const { legal, name } = usePage().props;

    return (
        <footer className="px-6 py-4 text-center text-xs text-muted-foreground">
            <nav
                aria-label="Legal"
                className="flex flex-wrap justify-center gap-x-4 gap-y-1"
            >
                <Link href={privacy()} className="underline underline-offset-4">
                    Privacy policy
                </Link>
                <Link href={terms()} className="underline underline-offset-4">
                    Terms of service
                </Link>
                <Link href={cookies()} className="underline underline-offset-4">
                    Cookie policy
                </Link>
            </nav>
            <p className="mt-2">
                {legal.company_name ?? name}
                {legal.registration_number &&
                    `, org. no. ${legal.registration_number}`}
                {legal.contact_email && (
                    <>
                        {', '}
                        <a
                            href={`mailto:${legal.contact_email}`}
                            className="underline underline-offset-4"
                        >
                            {legal.contact_email}
                        </a>
                    </>
                )}
            </p>
        </footer>
    );
}
