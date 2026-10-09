import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { NoAccountNotice } from '@/components/no-account-notice';
import { dashboard } from '@/routes';
import cronJobs from '@/routes/cron-jobs';
import dns from '@/routes/dns';
import domains from '@/routes/domains';
import mail from '@/routes/mail';
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

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unit]}`;
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
}: {
    account: DashboardAccount | null;
    overview: Overview | null;
    usage: Usage | null;
}) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6 p-4">
                {account ? (
                    <>
                        <div className="flex items-center justify-between gap-4">
                            <Heading
                                title={account.name}
                                description="An overview of your account"
                            />

                            {account.suspended_at && (
                                <span className="text-sm font-medium text-red-600 dark:text-red-400">
                                    Suspended
                                </span>
                            )}
                        </div>

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
                                title="Web domains"
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
                    <NoAccountNotice />
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
