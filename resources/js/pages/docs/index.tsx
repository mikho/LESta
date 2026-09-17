import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import docs from '@/routes/docs';
import type { DocsGuideSummary } from '@/types';

export default function Index({ guides }: { guides: DocsGuideSummary[] }) {
    return (
        <>
            <Head title="Documentation" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Documentation"
                    description="Guides for using and administering LESta"
                />

                <div className="space-y-4">
                    {guides.map((guide) => (
                        <Link
                            key={guide.slug}
                            href={docs.show(guide.slug)}
                            className="block rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-sidebar-accent dark:border-sidebar-border"
                        >
                            <p className="font-medium">{guide.title}</p>
                            <p className="text-sm text-muted-foreground">
                                {guide.description}
                            </p>
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
}

Index.layout = {
    breadcrumbs: [
        {
            title: 'Documentation',
            href: docs.index(),
        },
    ],
};
