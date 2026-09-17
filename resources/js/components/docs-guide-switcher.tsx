import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import docs from '@/routes/docs';
import type { DocsGuideSummary } from '@/types';

/**
 * Links to the other guides this same user can open, filtered server-side (DocsController) the
 * same way the /docs index itself is -- an end-user never sees a link into the Admin or
 * Installation guide here, not even when they're deep in a chapter of the User Guide.
 */
export function DocsGuideSwitcher({
    guides,
    activeSlug,
}: {
    guides: DocsGuideSummary[];
    activeSlug: string;
}) {
    return (
        <nav className="flex flex-wrap gap-2 border-b border-sidebar-border/70 pb-4 dark:border-sidebar-border">
            {guides.map((guide) => (
                <Link
                    key={guide.slug}
                    href={docs.show(guide.slug)}
                    className={cn(
                        'rounded-full px-3 py-1 text-sm transition-colors',
                        guide.slug === activeSlug
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground hover:bg-sidebar-accent',
                    )}
                >
                    {guide.title}
                </Link>
            ))}
        </nav>
    );
}
