import { Head, Link } from '@inertiajs/react';
import {
    ArchiveRestore,
    BarChart3,
    BookOpen,
    Database,
    Download,
    FileText,
    FolderOpen,
    KeyRound,
    Mail as MailIcon,
} from 'lucide-react';
import type { ComponentType } from 'react';
import Heading from '@/components/heading';
import { NoAccountNotice } from '@/components/no-account-notice';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import accountBackups from '@/routes/account-backups';
import docs from '@/routes/docs';
import domains from '@/routes/domains';
import mail from '@/routes/mail';
import profile from '@/routes/profile';
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

const accountShortcuts: Shortcut[] = [
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
        description: 'Guides for using LESta',
        href: docs.index().url,
        icon: BookOpen,
    },
];

export default function Index({ websites, moreWebsites }: Props) {
    return (
        <>
            <Head title="Tools" />

            <div className="mx-auto w-full max-w-4xl space-y-8 p-4">
                <Heading
                    title="Tools"
                    description="Shortcuts to what you do most"
                />

                {websites === null ? (
                    <NoAccountNotice />
                ) : (
                    <>
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

                        <section
                            className="space-y-3"
                            aria-labelledby="tools-account"
                        >
                            <h2
                                id="tools-account"
                                className="text-base font-medium"
                            >
                                Your account
                            </h2>
                            <ul className="grid gap-3 sm:grid-cols-2">
                                {accountShortcuts.map((shortcut) => (
                                    <li key={shortcut.title}>
                                        {shortcut.download ? (
                                            <a
                                                href={shortcut.href}
                                                className="flex h-full items-start gap-3 rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-sidebar-accent dark:border-sidebar-border"
                                            >
                                                <ShortcutBody
                                                    shortcut={shortcut}
                                                />
                                            </a>
                                        ) : (
                                            <Link
                                                href={shortcut.href}
                                                className="flex h-full items-start gap-3 rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-sidebar-accent dark:border-sidebar-border"
                                            >
                                                <ShortcutBody
                                                    shortcut={shortcut}
                                                />
                                            </Link>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </>
                )}
            </div>
        </>
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
