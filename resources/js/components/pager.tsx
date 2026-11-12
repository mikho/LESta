import { Link } from '@inertiajs/react';
import type { Paginated } from '@/types';

type Props = {
    items: Pick<
        Paginated<unknown>,
        'current_page' | 'last_page' | 'prev_page_url' | 'next_page_url'
    >;
    /** What the pages hold, for the nav's accessible name: "Nodes pages". */
    label: string;
    /** The link texts, when the list runs the other way (the audit log's "Newer" and "Older"). */
    previous?: string;
    next?: string;
};

/**
 * Previous and Next for one page of a Laravel paginator. Nothing is rendered for a single page;
 * otherwise both links are always present, so focus never lands nowhere: a link when there is a
 * page that way, inert text when there is not.
 */
export function Pager({
    items,
    label,
    previous = 'Previous',
    next = 'Next',
}: Props) {
    if (items.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label={`${label} pages`}
            className="flex items-center gap-4 text-sm text-muted-foreground"
        >
            <span>
                Page {items.current_page} of {items.last_page}
            </span>
            <PageLink href={items.prev_page_url} label={previous} />
            <PageLink href={items.next_page_url} label={next} />
        </nav>
    );
}

function PageLink({ href, label }: { href: string | null; label: string }) {
    return href ? (
        <Link
            href={href}
            preserveScroll
            className="underline underline-offset-4 pointer-coarse:py-3"
        >
            {label}
        </Link>
    ) : (
        <span aria-disabled="true" className="opacity-50">
            {label}
        </span>
    );
}
