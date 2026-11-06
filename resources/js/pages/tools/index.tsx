import { Head, Link, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    BarChart3,
    BookOpen,
    Building2,
    Database,
    Download,
    FileText,
    FolderOpen,
    KeyRound,
    Mail as MailIcon,
    PackageIcon,
    ScrollText,
    Server,
    ShieldCheck,
} from 'lucide-react';
import type { ComponentType } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import accountBackups from '@/routes/account-backups';
import accounts from '@/routes/accounts';
import auditLog from '@/routes/audit-log';
import backups from '@/routes/backups';
import docs from '@/routes/docs';
import domains from '@/routes/domains';
import mail from '@/routes/mail';
import nodes from '@/routes/nodes';
import packages from '@/routes/packages';
import profile from '@/routes/profile';
import roles from '@/routes/roles';
import { edit as editSecurity } from '@/routes/security';
import tenantDatabases from '@/routes/tenant-databases';
import tools from '@/routes/tools';
import usage from '@/routes/usage';

type Website = { uuid: string; domain: string };

type Props = {
    websites: Website[] | null;
    moreWebsites: number;
};

type Shortcut = {
    title: string;
    description: string;
    href: string;
    icon: ComponentType<{ className?: string }>;
    download?: boolean;
};

const hostingShortcuts: Shortcut[] = [
    {
        title: 'Mail',
        description: 'Mailboxes, forwarders and webmail',
        href: mail.index().url,
        icon: MailIcon,
    },
    {
        title: 'Databases',
        description: 'Create databases and open Adminer',
        href: tenantDatabases.index().url,
        icon: Database,
    },
    {
        title: 'Backups',
        description: 'Download a copy of your hosting or restore one',
        href: accountBackups.index().url,
        icon: ArchiveRestore,
    },
];

const generalShortcuts: Shortcut[] = [
    {
        title: 'Usage',
        description: 'Disk, traffic and plan limits',
        href: usage.index().url,
        icon: BarChart3,
    },
    {
        title: 'Security',
        description: 'Password, two-factor and passkeys',
        href: editSecurity().url,
        icon: KeyRound,
    },
    {
        title: 'Your data',
        description: 'Download everything stored about you',
        href: profile.export().url,
        icon: Download,
        download: true,
    },
    {
        title: 'Documentation',
        description: 'Guides for using and administering LESta',
        href: docs.index().url,
        icon: BookOpen,
    },
];

const nodesShortcut: Shortcut = {
    title: 'Nodes',
    description: 'Servers, their status and enrollment',
    href: nodes.index().url,
    icon: Server,
};

const adminShortcuts: Shortcut[] = [
    {
        title: 'Customer accounts',
        description: 'View and manage hosting accounts',
        href: accounts.index().url,
        icon: Building2,
    },
    {
        title: 'Packages',
        description: 'Plans and their limits',
        href: packages.index().url,
        icon: PackageIcon,
    },
    nodesShortcut,
    {
        title: 'Node backups',
        description: 'Whole-node backups and restores',
        href: backups.index().url,
        icon: Archive,
    },
    {
        title: 'Roles',
        description: 'Roles and their permissions',
        href: roles.index().url,
        icon: ShieldCheck,
    },
];

const auditLogShortcut: Shortcut = {
    title: 'Audit log',
    description: 'Who changed what, and when',
    href: auditLog.index().url,
    icon: ScrollText,
};

export default function Index({ websites, moreWebsites }: Props) {
    const { auth } = usePage().props;

    const adminShortcutsForUser: Shortcut[] = auth.is_provider_admin
        ? adminShortcuts
        : auth.has_node_admin_grants
          ? [nodesShortcut]
          : [];

    if (auth.can_view_audit_log && !auth.has_any_account_membership) {
        adminShortcutsForUser.push(auditLogShortcut);
    }

    const accountShortcuts = [
        ...(websites === null ? [] : hostingShortcuts),
        ...generalShortcuts,
        ...(auth.has_any_account_membership ? [auditLogShortcut] : []),
    ];

    return (
        <>
            <Head title="Tools" />

            <div className="mx-auto w-full max-w-4xl space-y-8 p-4">
                <Heading
                    title="Tools"
                    description="Shortcuts to what you do most"
                />

                {websites !== null && (
                    <section
                        className="space-y-3"
                        aria-labelledby="tools-websites"
                    >
                        <h2
                            id="tools-websites"
                            className="text-base font-medium"
                        >
                            Websites
                        </h2>
                        {websites.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No websites yet.{' '}
                                <Link
                                    href={domains.create()}
                                    className="underline underline-offset-4"
                                >
                                    Add one
                                </Link>
                                .
                            </p>
                        ) : (
                            <ul className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                                {websites.map((website) => (
                                    <li
                                        key={website.uuid}
                                        className="flex flex-wrap items-center gap-2 p-3"
                                    >
                                        <span className="mr-auto font-medium">
                                            {website.domain}
                                        </span>
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={domains.files.index(
                                                    website.uuid,
                                                )}
                                            >
                                                <FolderOpen /> Files
                                            </Link>
                                        </Button>
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={domains.logs.index(
                                                    website.uuid,
                                                )}
                                            >
                                                <FileText /> Logs
                                            </Link>
                                        </Button>
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={domains.edit(
                                                    website.uuid,
                                                )}
                                            >
                                                Settings
                                            </Link>
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {moreWebsites > 0 && (
                            <p className="text-sm text-muted-foreground">
                                {moreWebsites} more on the{' '}
                                <Link
                                    href={domains.index()}
                                    className="underline underline-offset-4"
                                >
                                    Domains page
                                </Link>
                                .
                            </p>
                        )}
                    </section>
                )}

                <ShortcutSection
                    id="tools-account"
                    title="Your account"
                    shortcuts={accountShortcuts}
                />

                {adminShortcutsForUser.length > 0 && (
                    <ShortcutSection
                        id="tools-admin"
                        title="Administration"
                        shortcuts={adminShortcutsForUser}
                    />
                )}
            </div>
        </>
    );
}

function ShortcutSection({
    id,
    title,
    shortcuts,
}: {
    id: string;
    title: string;
    shortcuts: Shortcut[];
}) {
    return (
        <section className="space-y-3" aria-labelledby={id}>
            <h2 id={id} className="text-base font-medium">
                {title}
            </h2>
            <ul className="grid gap-3 sm:grid-cols-2">
                {shortcuts.map((shortcut) => {
                    const className =
                        'flex h-full items-start gap-3 rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-sidebar-accent dark:border-sidebar-border';

                    return (
                        <li key={shortcut.title}>
                            {shortcut.download ? (
                                <a href={shortcut.href} className={className}>
                                    <ShortcutBody shortcut={shortcut} />
                                </a>
                            ) : (
                                <Link
                                    href={shortcut.href}
                                    className={className}
                                >
                                    <ShortcutBody shortcut={shortcut} />
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

function ShortcutBody({ shortcut }: { shortcut: Shortcut }) {
    const Icon = shortcut.icon;

    return (
        <>
            <Icon className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            <span>
                <span className="block font-medium">{shortcut.title}</span>
                <span className="block text-sm text-muted-foreground">
                    {shortcut.description}
                </span>
            </span>
        </>
    );
}

Index.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Tools', href: tools.index() },
    ],
};
