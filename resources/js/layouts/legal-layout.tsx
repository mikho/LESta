import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LegalFooter } from '@/components/legal-footer';
import { home } from '@/routes';

export default function LegalLayout({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    const { name, legal } = usePage().props;

    return (
        <>
            <Head title={title} />
            <div className="flex min-h-svh flex-col bg-background text-foreground">
                <a
                    href="#main-content"
                    className="sr-only z-50 rounded-md bg-background px-4 py-2 text-sm font-medium shadow focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
                >
                    Skip to content
                </a>
                <header className="border-b px-6 py-4">
                    <Link
                        href={home()}
                        className="flex items-center gap-2 font-medium"
                    >
                        <AppLogoIcon className="size-6 fill-current" />
                        {name}
                    </Link>
                </header>
                <main
                    id="main-content"
                    tabIndex={-1}
                    className="mx-auto w-full max-w-3xl flex-1 space-y-6 px-6 py-10 outline-none [&_h2]:mt-8 [&_h2]:text-lg [&_h2]:font-semibold [&_li]:ml-5 [&_li]:list-disc [&_p]:leading-relaxed [&_table]:w-full [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2 [&_th]:text-left"
                >
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {title}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {legal.updated_at
                            ? `Last updated ${legal.updated_at}`
                            : 'Last updated: not configured'}
                    </p>
                    {children}
                </main>
                <LegalFooter />
            </div>
        </>
    );
}

export function Controller() {
    const { legal, name } = usePage().props;
    const missing = 'not configured';

    return (
        <section aria-labelledby="controller-title">
            <h2 id="controller-title">Who we are</h2>
            <p>
                {legal.company_name ?? name} (
                {legal.registration_number ?? missing})
                {legal.vat_number ? `, VAT ${legal.vat_number}` : ''}
                {', '}
                {legal.address ?? missing}. Contact:{' '}
                {legal.contact_email ? (
                    <a
                        href={`mailto:${legal.contact_email}`}
                        className="underline underline-offset-4"
                    >
                        {legal.contact_email}
                    </a>
                ) : (
                    missing
                )}
                .
            </p>
        </section>
    );
}
