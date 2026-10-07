import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import accounts from '@/routes/accounts';

export type CustomerListing<T> = {
    groups: {
        node: { uuid: string; name: string };
        totals: { resources: number; accounts: number };
        rows: {
            account: { public_id: string; name: string };
            item: T;
        }[];
    }[];
    nodes: string[];
    summary: { resources: number | null; accounts: number; nodes: number };
    filters: { node: string; account: string; status: string; sort: string };
    pagination: {
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
};

/** A list item as the customer view receives it: the resource, plus why its last operation failed. */
export type CustomerItem<T> = T & { provisioning_error: string | null };

export type CustomerColumn<T> = {
    header: string;
    cell: (item: T) => ReactNode;
    /** The `sort` query value this column sorts by; omit for a column that cannot be sorted. */
    sortKey?: string;
    /** Right-aligns the column and uses tabular figures so magnitudes can be compared. */
    numeric?: boolean;
};

type Noun = { one: string; other: string };

/**
 * Every sentence this surface shows, as whole messages with the numbers and names passed in, so a
 * translation can reorder them. There is no i18n library in the app yet; when one arrives these
 * become its message catalog.
 */
const plural = (count: number, noun: Noun): string =>
    new Intl.PluralRules(
        typeof document === 'undefined'
            ? undefined
            : document.documentElement.lang || undefined,
    ).select(count) === 'one'
        ? noun.one
        : noun.other;

const accountNoun: Noun = { one: 'account', other: 'accounts' };
const nodeNoun: Noun = { one: 'node', other: 'nodes' };

type Totals = { resources: number | null; accounts: number; nodes: number };

const messages = {
    nodeFilter: 'Node',
    allNodes: 'All nodes',
    accountFilter: 'Account',
    accountPlaceholder: 'Name, id or email',
    statusFilter: 'Show',
    allStatuses: 'Everything',
    problemsOnly: 'Problems only',
    clearFilters: 'Clear filters',
    readOnly: 'Read-only. Open an account to manage it.',
    updating: 'Updating the list…',
    accountColumn: 'Account',
    previous: 'Previous',
    next: 'Next',
    previousPage: 'Previous page',
    nextPage: 'Next page',
    caption: (title: string, node: string) => `${title} on node ${node}`,
    sortBy: (column: string) => `Sort by ${column}`,
    summary: (total: Totals, noun: Noun | undefined) =>
        noun && total.resources !== null
            ? `${total.resources} ${plural(total.resources, noun)} in ${total.accounts} ${plural(total.accounts, accountNoun)} on ${total.nodes} ${plural(total.nodes, nodeNoun)}`
            : `${total.accounts} ${plural(total.accounts, accountNoun)} on ${total.nodes} ${plural(total.nodes, nodeNoun)}`,
    page: (current: number, last: number) => `Page ${current} of ${last}`,
    nodeTotals: (
        totals: { resources: number; accounts: number },
        shown: number,
        noun: Noun | undefined,
    ) => {
        if (!noun) {
            return `${totals.accounts} ${plural(totals.accounts, accountNoun)}`;
        }

        const base = `${totals.resources} ${plural(totals.resources, noun)} in ${totals.accounts} ${plural(totals.accounts, accountNoun)}`;

        return shown < totals.resources
            ? `${base}, ${shown} shown on this page`
            : base;
    },
    activeFilters: (node: string, account: string, problems: boolean): string =>
        [
            node !== '' ? `Node: ${node}` : '',
            account !== '' ? `Account: ${account}` : '',
            problems ? 'Show: Problems only' : '',
        ]
            .filter(Boolean)
            .join(', '),
    noMatch: (message: string, filters: string) =>
        filters === '' ? message : `${message} (${filters})`,
    suspended: 'Suspended',
    accountSuspended: 'Account suspended',
    active: 'Active',
};

export function SuspensionCell({
    suspendedAt,
    source,
}: {
    suspendedAt: string | null;
    source: string | null;
}) {
    return suspendedAt ? (
        <span className="text-red-600 dark:text-red-400">
            {source === 'cascade'
                ? messages.accountSuspended
                : messages.suspended}
        </span>
    ) : (
        <span className="text-muted-foreground">{messages.active}</span>
    );
}

export const formatRelative = (iso: string): string => {
    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const formatter = new Intl.RelativeTimeFormat(undefined, {
        numeric: 'auto',
    });
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(Math.round(seconds), 'second');
};

export const formatCount = (value: number | null | undefined): string =>
    value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat().format(value);

const ALL = '__all__';

/**
 * The provider admin's read-only view of every customer's resources for one list page: one table
 * per node, with the owning account as its first (sticky) column, so columns line up across
 * accounts and the account is part of every row. Node and "show" filters are labelled selects, the
 * account filter is free text, columns marked sortable sort within each node, and one polite
 * status line announces what the list holds and when it is updating.
 */
export function CustomerResourceIndex<T extends { uuid: string }>({
    title,
    description,
    indexUrl,
    listing,
    columns,
    emptyMessage,
    emptyFilteredMessage,
    resourceNoun,
    showStatusFilter = true,
    accountFilterParam = 'account',
    accountHref = (publicId) => accounts.show({ public_id: publicId }),
}: {
    title: string;
    description: string;
    indexUrl: string;
    listing: CustomerListing<T>;
    columns: CustomerColumn<T>[];
    emptyMessage: string;
    emptyFilteredMessage: string;
    resourceNoun?: Noun;
    showStatusFilter?: boolean;
    accountFilterParam?: string;
    accountHref?: (publicId: string) => string | { url: string };
}) {
    const [node, setNode] = useState(listing.filters.node);
    const [account, setAccount] = useState(listing.filters.account);
    const [status, setStatus] = useState(listing.filters.status);
    const [sort, setSort] = useState(listing.filters.sort);
    const [loading, setLoading] = useState(false);
    const isFirstRender = useRef(true);
    const lastAccount = useRef(listing.filters.account);

    // What the list on screen was actually filtered by, not what is typed right now, so the
    // empty state never misdescribes a list that is still reloading.
    const listIsFiltered =
        listing.filters.node !== '' ||
        listing.filters.account !== '' ||
        listing.filters.status !== '';
    const inputsAreFiltered = node !== '' || account !== '' || status !== '';

    const visit = {
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
    };

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        // Typing waits for a pause; picking from a select or a column header applies at once.
        const delay = account === lastAccount.current ? 0 : 300;
        lastAccount.current = account;

        const timeout = setTimeout(() => {
            router.get(
                indexUrl,
                {
                    ...(node !== '' && { node }),
                    ...(account !== '' && { [accountFilterParam]: account }),
                    ...(status !== '' && { status }),
                    ...(sort !== '' && { sort }),
                },
                {
                    preserveState: true,
                    replace: true,
                    onStart: () => setLoading(true),
                    onFinish: () => setLoading(false),
                },
            );
        }, delay);

        return () => clearTimeout(timeout);
    }, [node, account, status, sort, indexUrl, accountFilterParam]);

    const clearFilters = () => {
        setNode('');
        setAccount('');
        setStatus('');
    };

    // none, then ascending, then descending, then none again.
    const nextSort = (key: string) =>
        sort === key ? `-${key}` : sort === `-${key}` ? '' : key;

    const sortState = (key: string | undefined) =>
        key === undefined
            ? undefined
            : sort === key
              ? 'ascending'
              : sort === `-${key}`
                ? 'descending'
                : 'none';

    const resolveHref = (publicId: string) => {
        const href = accountHref(publicId);

        return typeof href === 'string' ? href : href.url;
    };

    const empty = listing.groups.length === 0;
    const statusText = loading
        ? messages.updating
        : empty
          ? messages.noMatch(
                listIsFiltered ? emptyFilteredMessage : emptyMessage,
                messages.activeFilters(
                    listing.filters.node,
                    listing.filters.account,
                    listing.filters.status === 'problems',
                ),
            )
          : `${messages.summary(listing.summary, resourceNoun)}${
                listing.pagination.last_page > 1
                    ? `. ${messages.page(listing.pagination.current_page, listing.pagination.last_page)}`
                    : ''
            }`;

    return (
        <>
            <Head title={title} />

            <div className="space-y-6 p-4" data-test="customer-resource-index">
                <Heading level={1} title={title} description={description} />

                <div className="flex flex-wrap items-end gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="customer-node-filter">
                            {messages.nodeFilter}
                        </Label>
                        <Select
                            value={node === '' ? ALL : node}
                            onValueChange={(value) =>
                                setNode(value === ALL ? '' : value)
                            }
                        >
                            <SelectTrigger
                                id="customer-node-filter"
                                className="w-56 pointer-coarse:h-11"
                                data-test="customer-node-filter"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {messages.allNodes}
                                </SelectItem>
                                {listing.nodes.map((name) => (
                                    <SelectItem key={name} value={name}>
                                        {name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="customer-account-filter">
                            {messages.accountFilter}
                        </Label>
                        <Input
                            id="customer-account-filter"
                            type="search"
                            placeholder={messages.accountPlaceholder}
                            value={account}
                            onChange={(e) => setAccount(e.target.value)}
                            className="w-72 pointer-coarse:h-11"
                            data-test="customer-account-filter"
                        />
                    </div>

                    {showStatusFilter && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="customer-status-filter">
                                {messages.statusFilter}
                            </Label>
                            <Select
                                value={status === '' ? ALL : status}
                                onValueChange={(value) =>
                                    setStatus(value === ALL ? '' : value)
                                }
                            >
                                <SelectTrigger
                                    id="customer-status-filter"
                                    className="w-44 pointer-coarse:h-11"
                                    data-test="customer-status-filter"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        {messages.allStatuses}
                                    </SelectItem>
                                    <SelectItem value="problems">
                                        {messages.problemsOnly}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {inputsAreFiltered && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={clearFilters}
                            className="pointer-coarse:h-11"
                            data-test="customer-clear-filters"
                        >
                            {messages.clearFilters}
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                    <p
                        role="status"
                        aria-live="polite"
                        className={
                            empty && !loading
                                ? 'w-full rounded-xl border border-sidebar-border/70 p-6 text-center dark:border-sidebar-border'
                                : undefined
                        }
                    >
                        {statusText}
                    </p>
                    {!empty && <p>{messages.readOnly}</p>}
                </div>

                <div aria-busy={loading} className="space-y-8">
                    {listing.groups.map((group) => {
                        const headingId = `customer-node-${group.node.uuid}`;

                        return (
                            <section
                                key={group.node.uuid}
                                aria-labelledby={headingId}
                                className="space-y-3"
                            >
                                <div className="flex flex-wrap items-baseline gap-x-3">
                                    <h2
                                        id={headingId}
                                        className="text-base font-semibold"
                                    >
                                        {group.node.name}
                                    </h2>
                                    <span className="text-sm text-muted-foreground">
                                        {messages.nodeTotals(
                                            group.totals,
                                            group.rows.length,
                                            resourceNoun,
                                        )}
                                    </span>
                                </div>

                                <div
                                    role="region"
                                    aria-labelledby={headingId}
                                    tabIndex={0}
                                    className="overflow-x-auto rounded-xl border border-sidebar-border/70 outline-none focus-visible:ring-[3px] focus-visible:ring-ring dark:border-sidebar-border"
                                >
                                    <table className="w-full text-left text-sm">
                                        <caption className="sr-only">
                                            {messages.caption(
                                                title,
                                                group.node.name,
                                            )}
                                        </caption>
                                        <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                            <tr>
                                                <th
                                                    scope="col"
                                                    className="sticky left-0 z-10 bg-background px-4 py-2 font-medium"
                                                >
                                                    {messages.accountColumn}
                                                </th>
                                                {columns.map(
                                                    (column, index) => (
                                                        <th
                                                            key={index}
                                                            scope="col"
                                                            aria-sort={sortState(
                                                                column.sortKey,
                                                            )}
                                                            className={`px-4 py-2 font-medium ${
                                                                column.numeric
                                                                    ? 'text-right'
                                                                    : ''
                                                            }`}
                                                        >
                                                            {column.sortKey ? (
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        setSort(
                                                                            nextSort(
                                                                                column.sortKey as string,
                                                                            ),
                                                                        )
                                                                    }
                                                                    aria-label={messages.sortBy(
                                                                        column.header,
                                                                    )}
                                                                    className="inline-flex items-center gap-1 rounded-sm hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                                                >
                                                                    {
                                                                        column.header
                                                                    }
                                                                    <span aria-hidden="true">
                                                                        {sort ===
                                                                        column.sortKey
                                                                            ? '↑'
                                                                            : sort ===
                                                                                `-${column.sortKey}`
                                                                              ? '↓'
                                                                              : ''}
                                                                    </span>
                                                                </button>
                                                            ) : (
                                                                column.header
                                                            )}
                                                        </th>
                                                    ),
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {group.rows.map(
                                                (
                                                    { account: owner, item },
                                                    rowIndex,
                                                ) => {
                                                    const startsAccount =
                                                        rowIndex === 0 ||
                                                        group.rows[rowIndex - 1]
                                                            .account
                                                            .public_id !==
                                                            owner.public_id;

                                                    return (
                                                        <tr
                                                            key={item.uuid}
                                                            className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                                        >
                                                            <th
                                                                scope="row"
                                                                className="sticky left-0 z-10 bg-background px-4 py-2 text-left font-medium"
                                                            >
                                                                {startsAccount ? (
                                                                    <>
                                                                        <Link
                                                                            href={resolveHref(
                                                                                owner.public_id,
                                                                            )}
                                                                            className="inline-block py-1 underline pointer-coarse:py-3"
                                                                        >
                                                                            {
                                                                                owner.name
                                                                            }
                                                                        </Link>
                                                                        <span className="block text-xs font-normal text-muted-foreground">
                                                                            {
                                                                                owner.public_id
                                                                            }
                                                                        </span>
                                                                    </>
                                                                ) : (
                                                                    <span className="sr-only">
                                                                        {
                                                                            owner.name
                                                                        }
                                                                    </span>
                                                                )}
                                                            </th>
                                                            {columns.map(
                                                                (
                                                                    column,
                                                                    index,
                                                                ) => (
                                                                    <td
                                                                        key={
                                                                            index
                                                                        }
                                                                        className={`px-4 py-2 ${
                                                                            column.numeric
                                                                                ? 'text-right tabular-nums'
                                                                                : ''
                                                                        }`}
                                                                    >
                                                                        {column.cell(
                                                                            item,
                                                                        )}
                                                                    </td>
                                                                ),
                                                            )}
                                                        </tr>
                                                    );
                                                },
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        );
                    })}
                </div>

                {listing.pagination.last_page > 1 && (
                    <nav
                        aria-label="Pagination"
                        className="flex items-center gap-3 text-sm"
                    >
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            className="aria-disabled:pointer-events-none aria-disabled:opacity-50 pointer-coarse:h-11"
                        >
                            <Link
                                href={listing.pagination.prev_page_url ?? '#'}
                                aria-label={messages.previousPage}
                                aria-disabled={
                                    !listing.pagination.prev_page_url
                                }
                                tabIndex={
                                    listing.pagination.prev_page_url
                                        ? undefined
                                        : -1
                                }
                                onClick={(event) => {
                                    if (!listing.pagination.prev_page_url) {
                                        event.preventDefault();
                                    }
                                }}
                                preserveScroll
                                {...visit}
                            >
                                {messages.previous}
                            </Link>
                        </Button>
                        <span className="text-muted-foreground">
                            {messages.page(
                                listing.pagination.current_page,
                                listing.pagination.last_page,
                            )}
                        </span>
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            className="aria-disabled:pointer-events-none aria-disabled:opacity-50 pointer-coarse:h-11"
                        >
                            <Link
                                href={listing.pagination.next_page_url ?? '#'}
                                aria-label={messages.nextPage}
                                aria-disabled={
                                    !listing.pagination.next_page_url
                                }
                                tabIndex={
                                    listing.pagination.next_page_url
                                        ? undefined
                                        : -1
                                }
                                onClick={(event) => {
                                    if (!listing.pagination.next_page_url) {
                                        event.preventDefault();
                                    }
                                }}
                                preserveScroll
                                {...visit}
                            >
                                {messages.next}
                            </Link>
                        </Button>
                    </nav>
                )}
            </div>
        </>
    );
}
