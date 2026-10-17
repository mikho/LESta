import { Form, Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import auditLog from '@/routes/audit-log';

type AuditEntry = {
    id: number;
    at: string | null;
    action: string;
    resource: string;
    account: { public_id: string; name: string } | null;
    actor: { name: string; email: string | null } | null;
    via: { kind: 'panel' | 'api' | 'system'; token: string | null };
    ip_address: string | null;
};

type Paginated<T> = {
    data: T[];
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    events: Paginated<AuditEntry>;
    filters: { account: string; action: string; via: string };
    accounts: { public_id: string; name: string }[] | null;
    viewingAll: boolean;
};

/**
 * How a change was made: in the panel, through an API token (named, or revoked since), or by
 * the platform itself (a schedule, a node report, a cascade).
 */
function Via({ via }: { via: AuditEntry['via'] }) {
    if (via.kind === 'api') {
        return (
            <span>
                API token{' '}
                {via.token === null ? (
                    <span className="text-muted-foreground">(revoked)</span>
                ) : (
                    <span className="font-medium">“{via.token}”</span>
                )}
            </span>
        );
    }

    return <span>{via.kind === 'system' ? 'Platform' : 'Panel'}</span>;
}

export default function AuditLogIndex({
    events,
    filters,
    accounts,
    viewingAll,
}: Props) {
    const showAccountColumn = viewingAll || (accounts?.length ?? 0) > 1;

    return (
        <>
            <Head title="Audit log" />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
                <Heading
                    title="Audit log"
                    description={
                        viewingAll
                            ? 'Every change on the platform: who made it, when, and how.'
                            : 'Every change to your account: who made it, when, and whether in the panel or through an API token.'
                    }
                />

                <Form
                    action={auditLog.index.url()}
                    method="get"
                    className="flex flex-wrap items-end gap-3"
                >
                    {accounts !== null && accounts.length > 1 && (
                        <div className="grid gap-1">
                            <Label htmlFor="account">Account</Label>
                            <select
                                id="account"
                                name="account"
                                defaultValue={filters.account}
                                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">All my accounts</option>
                                {accounts.map((account) => (
                                    <option
                                        key={account.public_id}
                                        value={account.public_id}
                                    >
                                        {account.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {viewingAll && (
                        <div className="grid gap-1">
                            <Label htmlFor="account">Account id</Label>
                            <Input
                                id="account"
                                name="account"
                                defaultValue={filters.account}
                                placeholder="All accounts"
                                className="w-40"
                            />
                        </div>
                    )}

                    <div className="grid gap-1">
                        <Label htmlFor="action">Action starts with</Label>
                        <Input
                            id="action"
                            name="action"
                            defaultValue={filters.action}
                            placeholder="web_domain."
                            className="w-48"
                        />
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="via">Made</Label>
                        <select
                            id="via"
                            name="via"
                            defaultValue={filters.via}
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Any way</option>
                            <option value="panel">In the panel</option>
                            <option value="api">Through the API</option>
                            <option value="system">By the platform</option>
                        </select>
                    </div>

                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </Form>

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-sidebar-border/70 text-left dark:border-sidebar-border">
                                <th className="px-4 py-2 font-medium">When</th>
                                <th className="px-4 py-2 font-medium">
                                    Action
                                </th>
                                {showAccountColumn && (
                                    <th className="px-4 py-2 font-medium">
                                        Account
                                    </th>
                                )}
                                <th className="px-4 py-2 font-medium">Who</th>
                                <th className="px-4 py-2 font-medium">How</th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={showAccountColumn ? 5 : 4}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        Nothing recorded yet.
                                    </td>
                                </tr>
                            )}

                            {events.data.map((event) => (
                                <tr
                                    key={event.id}
                                    className="border-b border-sidebar-border/70 align-top last:border-0 dark:border-sidebar-border"
                                >
                                    <td className="px-4 py-2 whitespace-nowrap text-muted-foreground">
                                        {event.at === null
                                            ? ''
                                            : new Date(
                                                  event.at,
                                              ).toLocaleString()}
                                    </td>
                                    <td className="px-4 py-2">
                                        <div className="font-mono text-xs">
                                            {event.action}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {event.resource}
                                        </div>
                                    </td>
                                    {showAccountColumn && (
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {event.account?.name ?? ''}
                                        </td>
                                    )}
                                    <td className="px-4 py-2">
                                        {event.actor === null ? (
                                            <span className="text-muted-foreground">
                                                No one (automatic)
                                            </span>
                                        ) : (
                                            <>
                                                <div>{event.actor.name}</div>
                                                {event.actor.email && (
                                                    <div className="text-muted-foreground">
                                                        {event.actor.email}
                                                    </div>
                                                )}
                                            </>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">
                                        <Via via={event.via} />
                                        {event.ip_address && (
                                            <div className="text-muted-foreground">
                                                {event.ip_address}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>{events.total} total</span>

                    <div className="flex gap-2">
                        {events.prev_page_url && (
                            <Link
                                href={events.prev_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Newer
                            </Link>
                        )}
                        {events.next_page_url && (
                            <Link
                                href={events.next_page_url}
                                preserveScroll
                                className="underline"
                            >
                                Older
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

AuditLogIndex.layout = {
    breadcrumbs: [
        {
            title: 'Audit log',
            href: auditLog.index(),
        },
    ],
};
