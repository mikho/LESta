import { Head, Link } from '@inertiajs/react';
import { DocsGuideSwitcher } from '@/components/docs-guide-switcher';
import { DocsToc } from '@/components/docs-toc';
import Heading from '@/components/heading';
import { Markdown } from '@/components/markdown';
import docs from '@/routes/docs';
import type { DocsChapter, DocsGuide, DocsGuideSummary } from '@/types';

export default function Chapter({
    guide,
    chapter,
    guides,
}: {
    guide: DocsGuide;
    chapter: DocsChapter;
    guides: DocsGuideSummary[];
}) {
    return (
        <>
            <Head title={`${chapter.title} — ${guide.title}`} />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <DocsGuideSwitcher guides={guides} activeSlug={guide.slug} />

                <div className="flex flex-col gap-8 lg:flex-row">
                    <div className="min-w-0 flex-1 space-y-6">
                        <div className="space-y-1">
                            <Link
                                href={docs.show(guide.slug)}
                                className="text-sm text-muted-foreground underline"
                            >
                                {guide.title}
                            </Link>
                            <Heading
                                title={`Chapter ${chapter.number}: ${chapter.title}`}
                            />
                        </div>

                        <Markdown content={chapter.body} />

                        <div className="flex items-center justify-between gap-4 border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border">
                            {chapter.previous ? (
                                <Link
                                    href={docs.chapter([
                                        guide.slug,
                                        chapter.previous.slug,
                                    ])}
                                    className="text-sm underline"
                                >
                                    ← {chapter.previous.title}
                                </Link>
                            ) : (
                                <span />
                            )}

                            {chapter.next && (
                                <Link
                                    href={docs.chapter([
                                        guide.slug,
                                        chapter.next.slug,
                                    ])}
                                    className="text-sm underline"
                                >
                                    {chapter.next.title} →
                                </Link>
                            )}
                        </div>
                    </div>

                    <aside className="shrink-0 lg:w-56">
                        <div className="lg:sticky lg:top-4">
                            <DocsToc
                                guideSlug={guide.slug}
                                chapters={guide.chapters}
                                activeChapterSlug={chapter.slug}
                            />
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

Chapter.layout = {
    breadcrumbs: [
        {
            title: 'Documentation',
            href: docs.index(),
        },
    ],
};
