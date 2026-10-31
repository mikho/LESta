import { Head, Link, usePoll } from '@inertiajs/react';
import { CircleCheck, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useEffect } from 'react';
import Heading from '@/components/heading';
import { NoAccountNotice } from '@/components/no-account-notice';
import { usePollWhile } from '@/hooks/use-poll-while';
import { formatBytes, formatRelative } from '@/lib/format';
import { dashboard } from '@/routes';
import cronJobs from '@/routes/cron-jobs';
import dns from '@/routes/dns';
import domains from '@/routes/domains';
import mail from '@/routes/mail';
import nodes from '@/routes/nodes';
import tenantDatabases from '@/routes/tenant-databases';

type DashboardAccount = {
    name: string;
    suspended_at: string | null;
    web_domains_count: number;
    dns_zones_count: number;
    mail_domains_count: number;
    mail_accounts_count: number;
    tenant_databases_count: number;
    cron_jobs_count: number;
};

type Overview = {
    package: string;
    role: 'owner' | 'member';
    contact_email: string | null;
    primary_domain: {
        domain: string;
        uuid: string;
        ssl_mode: string;
        certificate_issued: boolean;
        ip_address: string | null;
        suspended: boolean;
    } | null;
    servers: { name: string; hostname: string }[];
    previous_sign_in: { at: string; ip: string | null } | null;
};

type Usage = {
    limits: {
        key: string;
        label: string;
        used: number;
        included: boolean;
        limit: number | null;
    }[];
    disk_bytes: number;
    bandwidth_bytes_30d: number;
    requests_30d: number;
    collected_at: string | null;
};

type Health = {
    attention: {
        kind: string;
        name: string;
        url: string;
        status: string;
        error: string | null;
    }[];
    applying: number;
};

type NodeStatus = {
    id: number;
    uuid: string;
    name: string;
    hostname: string;
    suspended: boolean;
    agent_version: string | null;
    heartbeat: { online: boolean; last_seen_at: string | null };
    ping: { ok: boolean; ms: number | null; at: string } | null;
    services: { name: string; state: string }[];
    capabilities: { capability: string; status: string }[];
};

function Dot({ tone }: { tone: 'up' | 'down' | 'unknown' }) {
    const colour = {
        up: 'bg-green-500',
        down: 'bg-red-500',
        unknown: 'bg-muted-foreground/40',
    }[tone];

    return (
        <span
            aria-hidden
            className={`inline-block size-2 shrink-0 rounded-full ${colour}`}
        />
    );
}

/**
 * The first thing on the page: whether the account's hosting is in order. Quiet when it is, a
 * list of exactly what needs looking at (with the server's reason and a link to fix it) when not.
 */
function HealthSummary({ health }: { health: Health }) {
    if (health.attention.length > 0) {
        return (
            <section
                aria-labelledby="health-heading"
                className="space-y-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/40"
            >
                <h2
                    id="health-heading"
                    className="flex items-center gap-2 font-medium"
                >
                    <TriangleAlert
                        aria-hidden
                        className="size-4 text-red-700 dark:text-red-400"
                    />
                    {health.attention.length === 1
                        ? '1 thing needs your attention'
                        : `${health.attention.length} things need your attention`}
                </h2>
                <ul className="space-y-2 text-sm">
                    {health.attention.map((item) => (
                        <li key={item.url}>
                            <Link
                                href={item.url}
                                className="font-medium underline underline-offset-4"
                            >
                                {item.kind} {item.name}
                            </Link>
                            <span className="text-muted-foreground">
                                {' '}
                                {item.status === 'degraded'
                                    ? 'no longer matches its settings on the server'
                                    : 'could not be updated on the server'}
                                {item.error ? `: ${item.error}` : '.'}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>
        );
    }

    return (
        <p
            role="status"
            className="flex items-center gap-2 text-sm text-muted-foreground"
        >
            {health.applying > 0 ? (
                <>
                    <LoaderCircle
                        aria-hidden
                        className="size-4 animate-spin text-blue-700 dark:text-blue-300"
                    />
                    {health.applying === 1
                        ? '1 change is being applied on the server.'
                        : `${health.applying} changes are being applied on the server.`}
                </>
            ) : (
                <>
                    <CircleCheck
                        aria-hidden
                        className="size-4 text-green-700 dark:text-green-400"
                    />
                    Everything is up to date on the server.
                </>
            )}
        </p>
    );
}

/**
 * What a brand-new account sees instead of six zeros: the order things are usually set up in,
 * each step a link.
 */
function GetStarted() {
    const steps = [
        {
            href: domains.create().url,
            title: 'Add your domain',
            body: 'Host a website on it. HTTPS is set up for you.',
        },
        {
            href: dns.create().url,
            title: 'Let this server answer for it',
            body: 'Add a DNS zone, then enter the name servers it shows where you registered the domain.',
        },
        {
            href: mail.create().url,
            title: 'Set up mail',
            body: 'Add the domain for mail and create mailboxes such as info@.',
        },
    ];

    return (
        <section
            aria-labelledby="get-started-heading"
            className="space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <div>
                <h2 id="get-started-heading" className="font-medium">
                    Get started
                </h2>
                <p className="text-sm text-muted-foreground">
                    Most sites are set up in this order. Each step can be done
                    on its own.
                </p>
            </div>
            <ol className="grid gap-3 sm:grid-cols-3">
                {steps.map((step) => (
                    <li key={step.title}>
                        <Link
                            href={step.href}
                            className="block h-full rounded-lg border p-3 hover:bg-muted"
                        >
                            <span className="block font-medium">
                                {step.title}
                            </span>
                            <span className="mt-1 block text-sm text-muted-foreground">
                                {step.body}
                            </span>
                        </Link>
                    </li>
                ))}
            </ol>
        </section>
    );
}

/**
 * One row per node: heartbeat (the node's daemon reaching the panel), ping (the panel reaching the
 * node), and the systemd services the node last reported. Refreshed every 30 seconds.
 */
function NodeStatusPanel({ nodeStatus }: { nodeStatus: NodeStatus[] }) {
    if (nodeStatus.length === 0) {
        return <p className="text-sm text-muted-foreground">No nodes yet.</p>;
    }

    return (
        <ul className="divide-y">
            {nodeStatus.map((node) => {
                const down = node.services.filter(
                    (service) => service.state !== 'active',
                );

                return (
                    <li
                        key={node.id}
                        className="grid gap-3 py-3 first:pt-0 last:pb-0 md:grid-cols-[minmax(0,14rem)_minmax(0,10rem)_minmax(0,10rem)_1fr]"
                    >
                        <div className="min-w-0">
                            <Link
                                href={nodes.edit(node.uuid).url}
                                className="font-medium hover:underline"
                            >
                                {node.name}
                            </Link>
                            <p className="truncate text-xs text-muted-foreground">
                                {node.hostname}
                                {node.agent_version &&
                                    ` · agent ${node.agent_version}`}
                            </p>
                            {node.suspended && (
                                <p className="text-xs font-medium text-red-600 dark:text-red-400">
                                    Suspended
                                </p>
                            )}
                        </div>

                        <div className="text-sm">
                            <p className="flex items-center gap-2">
                                <Dot
                                    tone={node.heartbeat.online ? 'up' : 'down'}
                                />
                                {node.heartbeat.online ? 'Online' : 'Offline'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Heartbeat{' '}
                                {node.heartbeat.last_seen_at === null
                                    ? 'never'
                                    : formatRelative(
                                          node.heartbeat.last_seen_at,
                                      )}
                            </p>
                        </div>

                        <div className="text-sm">
                            <p className="flex items-center gap-2">
                                <Dot
                                    tone={
                                        node.ping === null
                                            ? 'unknown'
                                            : node.ping.ok
                                              ? 'up'
                                              : 'down'
                                    }
                                />
                                {node.ping === null
                                    ? 'Not pinged yet'
                                    : node.ping.ok
                                      ? `Ping ${node.ping.ms} ms`
                                      : 'No ping reply'}
                            </p>
                            {node.ping && (
                                <p className="text-xs text-muted-foreground">
                                    Checked{' '}
                                    {node.ping.at === null
                                        ? 'never'
                                        : formatRelative(node.ping.at)}
                                </p>
                            )}
                        </div>

                        <div className="min-w-0 text-sm">
                            {node.services.length === 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    No service states reported yet (an agent
                                    older than this panel reports none).
                                </p>
                            ) : (
                                <>
                                    <p className="mb-1 flex items-center gap-2">
                                        <Dot
                                            tone={
                                                down.length === 0
                                                    ? 'up'
                                                    : 'down'
                                            }
                                        />
                                        {down.length === 0
                                            ? `All ${node.services.length} services up`
                                            : `${down.length} of ${node.services.length} services down`}
                                    </p>
                                    <ul className="flex flex-wrap gap-x-3 gap-y-1">
                                        {node.services.map((service) => (
                                            <li
                                                key={service.name}
                                                className="flex items-center gap-1.5 text-xs text-muted-foreground"
                                                title={service.state}
                                            >
                                                <Dot
                                                    tone={
                                                        service.state ===
                                                        'active'
                                                            ? 'up'
                                                            : 'down'
                                                    }
                                                />
                                                <span
                                                    className={
                                                        service.state ===
                                                        'active'
                                                            ? ''
                                                            : 'font-medium text-red-600 dark:text-red-400'
                                                    }
                                                >
                                                    {service.name}
                                                    {service.state !==
                                                        'active' &&
                                                        ` (${service.state})`}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </>
                            )}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

function OverviewList({ overview }: { overview: Overview }) {
    const domain = overview.primary_domain;
    const previous = overview.previous_sign_in;

    return (
        <dl className="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
            <dt className="text-muted-foreground">Plan</dt>
            <dd>{overview.package}</dd>
            <dt className="text-muted-foreground">Your role</dt>
            <dd className="capitalize">{overview.role}</dd>
            {overview.contact_email && (
                <>
                    <dt className="text-muted-foreground">Contact</dt>
                    <dd>{overview.contact_email}</dd>
                </>
            )}
            {domain && (
                <>
                    <dt className="text-muted-foreground">Primary domain</dt>
                    <dd>
                        {domain.domain}
                        <span className="text-muted-foreground">
                            {' '}
                            {domain.ssl_mode === 'none'
                                ? 'no HTTPS'
                                : domain.certificate_issued
                                  ? 'HTTPS active'
                                  : 'certificate pending'}
                            {domain.ip_address ? `, ${domain.ip_address}` : ''}
                        </span>
                    </dd>
                </>
            )}
            {overview.servers.length > 0 && (
                <>
                    <dt className="text-muted-foreground">
                        {overview.servers.length === 1 ? 'Server' : 'Servers'}
                    </dt>
                    <dd>{overview.servers.map((s) => s.name).join(', ')}</dd>
                </>
            )}
            <dt className="text-muted-foreground">Last sign-in</dt>
            <dd>
                {previous
                    ? `${new Date(previous.at).toLocaleString()}${previous.ip ? ` from ${previous.ip}` : ''}`
                    : 'This is your first sign-in on record'}
            </dd>
        </dl>
    );
}

function UsagePanel({ usage }: { usage: Usage }) {
    return (
        <div className="space-y-4">
            <ul className="space-y-3">
                {usage.limits.map((row) => {
                    const unlimited = row.included && row.limit === null;
                    const percent =
                        row.included && row.limit
                            ? Math.min(100, (row.used / row.limit) * 100)
                            : 0;
                    const full =
                        row.included &&
                        row.limit !== null &&
                        row.used >= row.limit;

                    return (
                        <li key={row.key} className="text-sm">
                            <div className="flex justify-between gap-4">
                                <span>{row.label}</span>
                                <span className="text-muted-foreground">
                                    {!row.included
                                        ? 'Not in your plan'
                                        : unlimited
                                          ? `${row.used}, unlimited`
                                          : `${row.used} of ${row.limit}`}
                                </span>
                            </div>
                            {row.included && !unlimited && (
                                <div
                                    role="progressbar"
                                    aria-label={row.label}
                                    aria-valuemin={0}
                                    aria-valuemax={row.limit ?? 0}
                                    aria-valuenow={row.used}
                                    className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        className={`h-full ${full ? 'bg-red-600 dark:bg-red-400' : 'bg-foreground/60'}`}
                                        style={{ width: `${percent}%` }}
                                    />
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>

            <dl className="grid grid-cols-3 gap-4 border-t pt-4 text-sm">
                <div>
                    <dt className="text-muted-foreground">Disk</dt>
                    <dd className="font-medium">
                        {formatBytes(usage.disk_bytes)}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Traffic, 30 days</dt>
                    <dd className="font-medium">
                        {formatBytes(usage.bandwidth_bytes_30d)}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Requests, 30 days</dt>
                    <dd className="font-medium">
                        {usage.requests_30d.toLocaleString()}
                    </dd>
                </div>
            </dl>

            <p className="text-xs text-muted-foreground">
                {usage.collected_at
                    ? `Measured ${new Date(usage.collected_at).toLocaleString()}`
                    : 'No measurements collected yet'}
            </p>
        </div>
    );
}

function ResourceCard({
    title,
    count,
    href,
}: {
    title: string;
    count: number;
    href: string;
}) {
    return (
        <Link
            href={href}
            className="rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-sidebar-accent dark:border-sidebar-border"
        >
            <p className="text-sm text-muted-foreground">{title}</p>
            <p className="text-2xl font-semibold">{count}</p>
        </Link>
    );
}

export default function Dashboard({
    account,
    overview,
    usage,
    health,
    nodeStatus,
}: {
    account: DashboardAccount | null;
    overview: Overview | null;
    usage: Usage | null;
    health: Health | null;
    nodeStatus: NodeStatus[] | null;
}) {
    usePollWhile((health?.applying ?? 0) > 0, ['health', 'account']);

    const { start, stop } = usePoll(
        30000,
        { only: ['nodeStatus'] },
        { autoStart: false },
    );
    const showNodes = nodeStatus !== null;

    useEffect(() => {
        if (!showNodes) {
            return;
        }

        start();

        return stop;
    }, [showNodes, start, stop]);

    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6 p-4">
                <Heading
                    title={account ? account.name : 'Dashboard'}
                    description={
                        account
                            ? 'Your hosting at a glance'
                            : 'The platform at a glance'
                    }
                />

                {nodeStatus && (
                    <section className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <div className="flex items-baseline justify-between gap-4">
                            <h2 className="text-sm font-semibold">
                                Node status
                            </h2>
                            <Link
                                href={nodes.index().url}
                                className="text-xs text-muted-foreground hover:underline"
                            >
                                All nodes
                            </Link>
                        </div>
                        <NodeStatusPanel nodeStatus={nodeStatus} />
                    </section>
                )}

                {account ? (
                    <>
                        {account.suspended_at ? (
                            <section
                                role="alert"
                                className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm dark:border-red-900 dark:bg-red-950/40"
                            >
                                <p className="font-medium">
                                    This account is suspended
                                </p>
                                <p className="text-muted-foreground">
                                    Its websites, mail, databases and cron jobs
                                    are stopped until it is unsuspended. Your
                                    files and data are kept. Contact your
                                    provider to find out why.
                                </p>
                            </section>
                        ) : (
                            health && <HealthSummary health={health} />
                        )}

                        {account.web_domains_count +
                            account.dns_zones_count +
                            account.mail_domains_count +
                            account.tenant_databases_count +
                            account.cron_jobs_count ===
                            0 && <GetStarted />}

                        <div className="grid gap-4 lg:grid-cols-2">
                            {overview && (
                                <section className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                                    <h2 className="text-sm font-semibold">
                                        Account
                                    </h2>
                                    <OverviewList overview={overview} />
                                </section>
                            )}
                            {usage && (
                                <section className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                                    <h2 className="text-sm font-semibold">
                                        Usage
                                    </h2>
                                    <UsagePanel usage={usage} />
                                </section>
                            )}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <ResourceCard
                                title="Websites"
                                count={account.web_domains_count}
                                href={domains.index().url}
                            />
                            <ResourceCard
                                title="DNS zones"
                                count={account.dns_zones_count}
                                href={dns.index().url}
                            />
                            <ResourceCard
                                title="Mail domains"
                                count={account.mail_domains_count}
                                href={mail.index().url}
                            />
                            <ResourceCard
                                title="Mailboxes"
                                count={account.mail_accounts_count}
                                href={mail.index().url}
                            />
                            <ResourceCard
                                title="Databases"
                                count={account.tenant_databases_count}
                                href={tenantDatabases.index().url}
                            />
                            <ResourceCard
                                title="Cron jobs"
                                count={account.cron_jobs_count}
                                href={cronJobs.index().url}
                            />
                        </div>
                    </>
                ) : (
                    !showNodes && <NoAccountNotice />
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
