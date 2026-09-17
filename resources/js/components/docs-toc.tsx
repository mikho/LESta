import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import docs from '@/routes/docs';
import type { DocsChapterSummary } from '@/types';

/**
 * The current guide's own full chapter list, shown alongside every page inside that guide (its
 * own index and each chapter) so a reader can jump anywhere without walking back through the
 * guide's index page first.
 */
export function DocsToc({
    guideSlug,
    chapters,
    activeChapterSlug,
}: {
    guideSlug: string;
    chapters: DocsChapterSummary[];
    activeChapterSlug?: string;
}) {
    return (
        <nav className="space-y-1 text-sm">
            <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                In this guide
            </p>

            {chapters.map((chapter) => (
                <Link
                    key={chapter.slug}
                    href={docs.chapter([guideSlug, chapter.slug])}
                    data-test={`toc-link-${chapter.slug}`}
                    className={cn(
                        'block rounded-md px-2 py-1.5 transition-colors',
                        chapter.slug === activeChapterSlug
                            ? 'bg-sidebar-accent font-medium'
                            : 'text-muted-foreground hover:bg-sidebar-accent',
                    )}
                >
                    {chapter.number}. {chapter.title}
                </Link>
            ))}
        </nav>
    );
}
