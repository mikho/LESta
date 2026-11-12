import { Head, Link, router } from '@inertiajs/react';
import { Ellipsis } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { ConfirmActionDialog } from '@/components/confirm-action-dialog';
import Heading from '@/components/heading';
import { Pager } from '@/components/pager';
import { ResourceStatus } from '@/components/provisioning-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { isApplying, usePollWhile } from '@/hooks/use-poll-while';
import type { Paginated } from '@/types';
import type { RouteDefinition, RouteFormDefinition } from '@/wayfinder';

type Confirmation = {
    form: RouteFormDefinition<'post' | 'patch' | 'put' | 'delete'>;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    confirmTestId?: string;
};

/**
 * One entry in a row's More menu: a page to go to, or an action that asks first.
 */
export type RowAction =
    | { label: string; href: RouteDefinition<'get'> | string; testId?: string }
    | { label: string; confirm: Confirmation; testId?: string };

export type OwnResourceColumn<T> = {
    header: string;
    cell: (item: T) => ReactNode;
    /** Left out on a phone, where the name, status and actions matter most. */
    hideOnMobile?: boolean;
};

type StatusFields = {
    uuid: string;
    suspended_at: string | null;
    suspension_source: string | null;
    provisioning_status: string | null;
    provisioning_error?: string | null;
};

/**
 * The list a customer sees of their own resources of one kind, the same on every such page: a
 * labelled search with an announced count, the resource's name as the way in, one status column
 * that stays quiet while all is well and explains itself when not, one Manage button per row and
 * everything else in a More menu, a first-run empty state that leads to Add, and a page that keeps
 * itself current while the server is still applying a change.
 */
export function OwnResourceList<T extends StatusFields>({
    title,
    description,
    headerActions,
    addHref,
    addLabel,
    indexUrl,
    search: initialSearch,
    noun,
    items,
    pollProp,
    columns,
    name,
    renderName,
    manageHref,
    moreActions,
    empty,
}: {
    title: string;
    description: string;
    headerActions?: ReactNode;
    addHref?: RouteDefinition<'get'>;
    addLabel?: string;
    indexUrl: string;
    search: string;
    noun: { one: string; other: string };
    items: Paginated<T>;
    pollProp: string;
    columns: OwnResourceColumn<T>[];
    name: (item: T) => string;
    /** What the name cell shows, when that is more than the plain name (a cron job's command). */
    renderName?: (item: T) => ReactNode;
    manageHref: (item: T) => RouteDefinition<'get'>;
    moreActions: (item: T) => RowAction[];
    empty: { title: string; body: string };
}) {
    const [search, setSearch] = useState(initialSearch);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                indexUrl,
                { search },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
    }, [search, indexUrl]);

    usePollWhile(
        items.data.some((item) => isApplying(item.provisioning_status)),
        [pollProp],
    );

    const searchId = `${pollProp}-search`;
    const hasNone = items.total === 0 && initialSearch === '';
    const countMessage =
        items.total === 0
            ? `No ${noun.other} match “${initialSearch}”.`
            : `${items.total} ${items.total === 1 ? noun.one : noun.other}`;

    return (
        <>
            <Head title={title} />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={title} description={description} />

                    {(headerActions || addHref) && (
                        <div className="flex flex-wrap items-center gap-2">
                            {headerActions}
                            {addHref && (
                                <Button asChild>
                                    <Link href={addHref}>{addLabel}</Link>
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                {hasNone ? (
                    <div className="rounded-xl border border-dashed p-8 text-center">
                        <h2 className="text-base font-medium">{empty.title}</h2>
                        <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                            {empty.body}
                        </p>
                        {addHref && (
                            <Button asChild className="mt-4">
                                <Link href={addHref}>{addLabel}</Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-3">
                            <Label htmlFor={searchId} className="sr-only">
                                Search {noun.other}
                            </Label>
                            <Input
                                id={searchId}
                                type="search"
                                placeholder={`Search ${noun.other}…`}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="max-w-sm pointer-coarse:h-11"
                            />
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                {countMessage}
                            </p>
                            {initialSearch !== '' && items.total === 0 && (
                                <Button
                                    variant="link"
                                    className="px-0"
                                    onClick={() => setSearch('')}
                                >
                                    Clear search
                                </Button>
                            )}
                        </div>

                        {items.data.length > 0 && (
                            <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                                <table className="w-full text-left text-sm">
                                    <caption className="sr-only">
                                        {title}
                                    </caption>
                                    <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-2 font-medium"
                                            >
                                                Name
                                            </th>
                                            {columns.map((column) => (
                                                <th
                                                    key={column.header}
                                                    scope="col"
                                                    className={`px-4 py-2 font-medium ${column.hideOnMobile ? 'hidden md:table-cell' : ''}`}
                                                >
                                                    {column.header}
                                                </th>
                                            ))}
                                            <th
                                                scope="col"
                                                className="hidden px-4 py-2 font-medium md:table-cell"
                                            >
                                                Status
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-2 text-right font-medium"
                                            >
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {items.data.map((item) => (
                                            <tr
                                                key={item.uuid}
                                                className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                            >
                                                <th
                                                    scope="row"
                                                    className="max-w-64 px-4 py-3 text-left font-medium"
                                                >
                                                    <Link
                                                        href={manageHref(item)}
                                                        className="break-words underline-offset-4 hover:underline"
                                                    >
                                                        {renderName
                                                            ? renderName(item)
                                                            : name(item)}
                                                    </Link>
                                                    <div className="mt-1 font-normal md:hidden">
                                                        <ResourceStatus
                                                            suspendedAt={
                                                                item.suspended_at
                                                            }
                                                            suspensionSource={
                                                                item.suspension_source
                                                            }
                                                            status={
                                                                item.provisioning_status
                                                            }
                                                            reason={
                                                                item.provisioning_error
                                                            }
                                                        />
                                                    </div>
                                                </th>
                                                {columns.map((column) => (
                                                    <td
                                                        key={column.header}
                                                        className={`px-4 py-3 text-muted-foreground ${column.hideOnMobile ? 'hidden md:table-cell' : ''}`}
                                                    >
                                                        {column.cell(item)}
                                                    </td>
                                                ))}
                                                <td className="hidden px-4 py-3 md:table-cell">
                                                    <ResourceStatus
                                                        suspendedAt={
                                                            item.suspended_at
                                                        }
                                                        suspensionSource={
                                                            item.suspension_source
                                                        }
                                                        status={
                                                            item.provisioning_status
                                                        }
                                                        reason={
                                                            item.provisioning_error
                                                        }
                                                    />
                                                </td>
                                                <td className="px-4 py-3">
                                                    <RowActions
                                                        name={name(item)}
                                                        manageHref={manageHref(
                                                            item,
                                                        )}
                                                        actions={moreActions(
                                                            item,
                                                        )}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        <Pager items={items} label={title} />
                    </>
                )}
            </div>
        </>
    );
}

function RowActions({
    name,
    manageHref,
    actions,
}: {
    name: string;
    manageHref: RouteDefinition<'get'>;
    actions: RowAction[];
}) {
    return (
        <div className="flex items-center justify-end gap-1">
            <Button
                variant="outline"
                size="sm"
                asChild
                className="pointer-coarse:h-11"
            >
                <Link href={manageHref} aria-label={`Manage ${name}`}>
                    Manage
                </Link>
            </Button>

            <MoreActionsMenu name={name} actions={actions} />
        </div>
    );
}

/**
 * The secondary actions on one row, behind a button named for the row ("More actions for
 * example.com"): pages to go to first, then actions that ask before they act, a destructive one
 * in red.
 */
export function MoreActionsMenu({
    name,
    actions,
}: {
    name: string;
    actions: RowAction[];
}) {
    const [confirming, setConfirming] = useState<Confirmation | null>(null);
    const links = actions.filter(
        (action): action is Extract<RowAction, { href: unknown }> =>
            'href' in action,
    );
    const confirms = actions.filter(
        (action): action is Extract<RowAction, { confirm: unknown }> =>
            'confirm' in action,
    );

    if (actions.length === 0) {
        return null;
    }

    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`More actions for ${name}`}
                        className="size-8 pointer-coarse:size-11"
                    >
                        <Ellipsis aria-hidden className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {links.map((action) => (
                        <DropdownMenuItem
                            key={action.label}
                            asChild
                            data-test={action.testId}
                        >
                            <Link href={action.href}>{action.label}</Link>
                        </DropdownMenuItem>
                    ))}
                    {links.length > 0 && confirms.length > 0 && (
                        <DropdownMenuSeparator />
                    )}
                    {confirms.map((action) => (
                        <DropdownMenuItem
                            key={action.label}
                            data-test={action.testId}
                            variant={
                                action.confirm.destructive
                                    ? 'destructive'
                                    : 'default'
                            }
                            onSelect={() => setConfirming(action.confirm)}
                        >
                            {action.label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            {confirming && (
                <ConfirmActionDialog
                    {...confirming}
                    open
                    onOpenChange={(open) => !open && setConfirming(null)}
                />
            )}
        </>
    );
}
