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
        accounts: {
            account: { public_id: string; name: string };
            items: T[];
        }[];
    }[];
    nodes: string[];
    summary: { resources: number | null; accounts: number; nodes: number };
    filters: { node: string; account: string; status: string };
    pagination: {
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
};

export type CustomerColumn<T> = {
    header: string;
    cell: (item: T) => ReactNode;
};

type Noun = { one: string; other: string };

/**
 * Every sentence this surface shows, as whole messages with the numbers and names passed in, so a
 * translation can reorder them. There is no i18n library in the app yet; when one arrives these
 * become its message catalog.
 */
const plural = (count: number, noun: Noun): string =>
    new Intl.PluralRules(document.documentElement.lang || undefined).select(
        count,
    ) === 'one'
        ? noun.one
        : noun.other;

const accountNoun: Noun = { one: 'account', other: 'accounts' };
const nodeNoun: Noun = { one: 'node', other: 'nodes' };

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
    summary: (
        total: { resources: number | null; accounts: number; nodes: number },
        noun: Noun | undefined,
    ) =>
        noun && total.resources !== null
            ? `${total.resources} ${plural(total.resources, noun)} in ${total.accounts} ${plural(total.accounts, accountNoun)} on ${total.nodes} ${plural(total.nodes, nodeNoun)}`
            : `${total.accounts} ${plural(total.accounts, accountNoun)} on ${total.nodes} ${plural(total.nodes, nodeNoun)}`,
    nodeTotals: (
        totals: { resources: number; accounts: number },
        noun: Noun | undefined,
    ) =>
        noun
            ? `${totals.resources} ${plural(totals.resources, noun)} in ${totals.accounts} ${plural(totals.accounts, accountNoun)}`
            : `${totals.accounts} ${plural(totals.accounts, accountNoun)}`,
    pageStatus: (current: number, last: number, total: number) =>
        `Page ${current} of ${last} (${total} total)`,
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
        <span className="text-green-700 dark:text-green-400">
            {messages.active}
        </span>
    );
}

const ALL = '__all__';

/**
 * The provider admin's read-only view of every customer's resources for one list page: one table
 * per node, with the owning account as its first column, so columns line up across accounts and
 * the account is part of every row. The node and "show" filters are labelled selects, the account
 * filter is free text, and a polite status line announces what the list now holds.
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
}) {
    const [node, setNode] = useState(listing.filters.node);
    const [account, setAccount] = useState(listing.filters.account);
    const [status, setStatus] = useState(listing.filters.status);
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

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        // Typing waits for a pause; picking from a select applies at once.
        const delay = account === lastAccount.current ? 0 : 300;
        lastAccount.current = account;

        const timeout = setTimeout(() => {
            router.get(
                indexUrl,
                {
                    ...(node !== '' && { node }),
                    ...(account !== '' && { [accountFilterParam]: account }),
                    ...(status !== '' && { status }),
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
    }, [node, account, status, indexUrl, accountFilterParam]);

    const clearFilters = () => {
        setNode('');
        setAccount('');
        setStatus('');
    };

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
                            data-test="customer-clear-filters-inline"
                        >
                            {messages.clearFilters}
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                    <p role="status" aria-live="polite">
                        {loading
                            ? messages.updating
                            : listing.groups.length > 0
                              ? messages.summary(listing.summary, resourceNoun)
                              : ''}
                    </p>
                    <p>{messages.readOnly}</p>
                </div>

                {listing.groups.length === 0 && (
                    <div
                        role="status"
                        className="space-y-3 rounded-xl border border-sidebar-border/70 p-6 text-center dark:border-sidebar-border"
                    >
                        <p className="text-sm text-muted-foreground">
                            {listIsFiltered
                                ? emptyFilteredMessage
                                : emptyMessage}
                        </p>

                        {listIsFiltered && (
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
                )}

                <div aria-busy={loading} className="space-y-8">
                    {listing.groups.map((group) => {
                        const headingId = `customer-node-${group.node.uuid}`;
                        const rows = group.accounts.flatMap(
                            ({ account: owner, items }) =>
                                items.map((item, index) => ({
                                    owner,
                                    item,
                                    firstOfAccount: index === 0,
                                })),
                        );

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
                                                    className="px-4 py-2 font-medium"
                                                >
                                                    {messages.accountColumn}
                                                </th>
                                                {columns.map(
                                                    (column, index) => (
                                                        <th
                                                            key={index}
                                                            scope="col"
                                                            className="px-4 py-2 font-medium"
                                                        >
                                                            {column.header}
                                                        </th>
                                                    ),
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {rows.map(
                                                ({
                                                    owner,
                                                    item,
                                                    firstOfAccount,
                                                }) => (
                                                    <tr
                                                        key={item.uuid}
                                                        className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                                    >
                                                        <th
                                                            scope="row"
                                                            className="px-4 py-2 text-left font-medium"
                                                        >
                                                            {firstOfAccount ? (
                                                                <Link
                                                                    href={accounts.show(
                                                                        {
                                                                            public_id:
                                                                                owner.public_id,
                                                                        },
                                                                    )}
                                                                    className="inline-block py-1 underline pointer-coarse:py-3"
                                                                >
                                                                    {owner.name}
                                                                </Link>
                                                            ) : (
                                                                <span className="sr-only">
                                                                    {owner.name}
                                                                </span>
                                                            )}
                                                        </th>
                                                        {columns.map(
                                                            (column, index) => (
                                                                <td
                                                                    key={index}
                                                                    className="px-4 py-2"
                                                                >
                                                                    {column.cell(
                                                                        item,
                                                                    )}
                                                                </td>
                                                            ),
                                                        )}
                                                    </tr>
                                                ),
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
                        {listing.pagination.prev_page_url ? (
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="pointer-coarse:h-11"
                            >
                                <Link
                                    href={listing.pagination.prev_page_url}
                                    aria-label={messages.previousPage}
                                >
                                    {messages.previous}
                                </Link>
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled
                                aria-label={messages.previousPage}
                                className="pointer-coarse:h-11"
                            >
                                {messages.previous}
                            </Button>
                        )}
                        <span className="text-muted-foreground">
                            {messages.pageStatus(
                                listing.pagination.current_page,
                                listing.pagination.last_page,
                                listing.pagination.total,
                            )}
                        </span>
                        {listing.pagination.next_page_url ? (
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="pointer-coarse:h-11"
                            >
                                <Link
                                    href={listing.pagination.next_page_url}
                                    aria-label={messages.nextPage}
                                >
                                    {messages.next}
                                </Link>
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled
                                aria-label={messages.nextPage}
                                className="pointer-coarse:h-11"
                            >
                                {messages.next}
                            </Button>
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}
