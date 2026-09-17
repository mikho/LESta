import { Head, Link } from '@inertiajs/react';
import { DocsGuideSwitcher } from '@/components/docs-guide-switcher';
import { DocsToc } from '@/components/docs-toc';
import Heading from '@/components/heading';
import { Markdown } from '@/components/markdown';
import docs from '@/routes/docs';
import type { DocsGuide, DocsGuideSummary } from '@/types';

export default function Show({
    guide,
    guides,
}: {
    guide: DocsGuide;
    guides: DocsGuideSummary[];
}) {
    return (
        <>
            <Head title={guide.title} />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <DocsGuideSwitcher guides={guides} activeSlug={guide.slug} />

                <div className="flex flex-col gap-8 lg:flex-row">
                    <div className="min-w-0 flex-1 space-y-6">
                        <Heading
                            title={guide.title}
                            description={guide.description}
                        />

                        {guide.intro && <Markdown content={guide.intro} />}

                        <div className="space-y-2">
                            {guide.chapters.map((chapter) => (
                                <Link
                                    key={chapter.slug}
                                    href={docs.chapter([
                                        guide.slug,
                                        chapter.slug,
                                    ])}
                                    data-test={`chapter-link-${chapter.slug}`}
                                    className="block rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-sidebar-accent dark:border-sidebar-border"
                                >
                                    <p className="font-medium">
                                        Chapter {chapter.number}:{' '}
                                        {chapter.title}
                                    </p>
                                </Link>
                            ))}
                        </div>
                    </div>

                    <aside className="shrink-0 lg:w-56">
                        <div className="lg:sticky lg:top-4">
                            <DocsToc
                                guideSlug={guide.slug}
                                chapters={guide.chapters}
                            />
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

Show.layout = {
    breadcrumbs: [
        {
            title: 'Documentation',
            href: docs.index(),
        },
    ],
};
