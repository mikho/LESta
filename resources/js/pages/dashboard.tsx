import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
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
}: {
    account: DashboardAccount | null;
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
                    <div className="space-y-4 rounded-xl border border-sidebar-border/70 p-8 text-center dark:border-sidebar-border">
                        <Heading
                            title="Welcome"
                            description="You are not a member of any account yet."
                        />
                        <p className="text-sm text-muted-foreground">
                            An administrator or reseller needs to set up a
                            hosting account for you before there is anything to
                            manage here.
                        </p>
                    </div>
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
