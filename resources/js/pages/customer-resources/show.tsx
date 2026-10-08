import { Head, Link } from '@inertiajs/react';
import { formatRelative } from '@/components/customer-resource-index';
import Heading from '@/components/heading';
import { ProvisioningBadge } from '@/components/provisioning-badge';
import accounts from '@/routes/accounts';

type Fact = {
    label: string;
    value: string;
    kind: 'text' | 'code' | 'datetime';
};

type Table = { title: string; columns: string[]; rows: string[][] };

type Operation = {
    at: string;
    capability: string;
    operation: string;
    status: string;
    error: string | null;
};

type Resource = {
    type_label: string;
    list_url: string;
    title: string;
    account: { public_id: string; name: string };
    node: string;
    suspended: { at: string; source: string | null } | null;
    facts: Fact[];
    tables: Table[];
    operations: Operation[];
};

const looksLikeTimestamp = (value: string) =>
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/.test(value);

function Moment({ iso }: { iso: string }) {
    return (
        <time
            dateTime={iso}
            title={new Date(iso).toLocaleString(undefined, {
                dateStyle: 'medium',
                timeStyle: 'medium',
                timeZoneName: 'short',
            })}
        >
            {formatRelative(iso)}
        </time>
    );
}

export default function Show({ resource }: { resource: Resource }) {
    return (
        <>
            <Head title={resource.title} />

            <div className="space-y-8 p-4" data-test="customer-resource-show">
                <div className="space-y-2">
                    <Link
                        href={resource.list_url}
                        className="text-sm underline"
                    >
                        Back to {resource.type_label}
                    </Link>

                    <Heading
                        level={1}
                        title={resource.title}
                        description={`${resource.type_label} for ${resource.account.name} on ${resource.node}. Read-only.`}
                    />

                    {resource.suspended && (
                        <p className="text-sm text-red-600 dark:text-red-400">
                            {resource.suspended.source === 'cascade'
                                ? 'Account suspended'
                                : 'Suspended'}{' '}
                            <Moment iso={resource.suspended.at} />
                        </p>
                    )}
                </div>

                <dl className="grid max-w-3xl gap-x-8 gap-y-3 text-sm sm:grid-cols-[14rem_1fr]">
                    {resource.facts.map((fact) => (
                        <div key={fact.label} className="contents">
                            <dt className="text-muted-foreground">
                                {fact.label}
                            </dt>
                            <dd className="break-words">
                                {fact.label === 'Account' ? (
                                    <Link
                                        href={accounts.show({
                                            public_id:
                                                resource.account.public_id,
                                        })}
                                        className="underline"
                                    >
                                        {fact.value}
                                    </Link>
                                ) : fact.kind === 'datetime' &&
                                  looksLikeTimestamp(fact.value) ? (
                                    <Moment iso={fact.value} />
                                ) : fact.kind === 'code' ? (
                                    <code>{fact.value}</code>
                                ) : (
                                    fact.value
                                )}
                            </dd>
                        </div>
                    ))}
                </dl>

                {resource.tables.map((table) => (
                    <section key={table.title} className="space-y-2">
                        <h2 className="text-base font-semibold">
                            {table.title}
                        </h2>
                        <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table className="w-full text-left text-sm">
                                <caption className="sr-only">
                                    {table.title}
                                </caption>
                                <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                    <tr>
                                        {table.columns.map((column) => (
                                            <th
                                                key={column}
                                                scope="col"
                                                className="px-4 py-2 font-medium"
                                            >
                                                {column}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {table.rows.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={table.columns.length}
                                                className="px-4 py-6 text-center text-muted-foreground"
                                            >
                                                Nothing here yet.
                                            </td>
                                        </tr>
                                    )}
                                    {table.rows.map((row, rowIndex) => (
                                        <tr
                                            key={rowIndex}
                                            className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                        >
                                            {row.map((cell, cellIndex) => (
                                                <td
                                                    key={cellIndex}
                                                    className="px-4 py-2 break-words"
                                                >
                                                    {looksLikeTimestamp(
                                                        cell,
                                                    ) ? (
                                                        <Moment iso={cell} />
                                                    ) : (
                                                        cell
                                                    )}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ))}

                <section className="space-y-2">
                    <h2 className="text-base font-semibold">
                        Recent operations
                    </h2>
                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">
                                Recent provisioning operations
                            </caption>
                            <thead className="border-b border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border">
                                <tr>
                                    <th
                                        scope="col"
                                        className="px-4 py-2 font-medium"
                                    >
                                        When
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-2 font-medium"
                                    >
                                        Capability
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-2 font-medium"
                                    >
                                        Operation
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-2 font-medium"
                                    >
                                        Result
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {resource.operations.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            No operations have been recorded.
                                        </td>
                                    </tr>
                                )}
                                {resource.operations.map((operation, index) => (
                                    <tr
                                        key={index}
                                        className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td className="px-4 py-2">
                                            <Moment iso={operation.at} />
                                        </td>
                                        <td className="px-4 py-2">
                                            <code>{operation.capability}</code>
                                        </td>
                                        <td className="px-4 py-2">
                                            {operation.operation}
                                        </td>
                                        <td className="px-4 py-2">
                                            <span className="inline-flex flex-col items-start gap-1">
                                                <ProvisioningBadge
                                                    status={operation.status}
                                                />
                                                {operation.error && (
                                                    <span className="max-w-xl text-xs text-muted-foreground">
                                                        {operation.error}
                                                    </span>
                                                )}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}
