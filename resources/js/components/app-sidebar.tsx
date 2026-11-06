import { Link, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    BarChart3,
    BookOpen,
    Building2,
    Clock,
    Database,
    Globe,
    LayoutGrid,
    Mail as MailIcon,
    Network,
    PackageIcon,
    ScrollText,
    Server,
    ShieldCheck,
    Wrench,
    UserRound,
} from 'lucide-react';
import { AccountPanel } from '@/components/account-panel';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import type { NavGroup } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import accountBackups from '@/routes/account-backups';
import accounts from '@/routes/accounts';
import auditLog from '@/routes/audit-log';
import backups from '@/routes/backups';
import cronJobs from '@/routes/cron-jobs';
import dns from '@/routes/dns';
import docs from '@/routes/docs';
import domains from '@/routes/domains';
import mail from '@/routes/mail';
import nodes from '@/routes/nodes';
import packages from '@/routes/packages';
import roles from '@/routes/roles';
import tenantDatabases from '@/routes/tenant-databases';
import tools from '@/routes/tools';
import usage from '@/routes/usage';
import type { NavItem } from '@/types';

/**
 * The sidebar follows what people come to do, not how the server is built: the hosting itself
 * first, then their own account, then (for provider staff) running the platform, then help.
 */
const hostingNavItems: NavItem[] = [
    { title: 'Domains', href: domains.index(), icon: Globe },
    { title: 'Mail', href: mail.index(), icon: MailIcon },
    { title: 'DNS', href: dns.index(), icon: Network },
    { title: 'Databases', href: tenantDatabases.index(), icon: Database },
    { title: 'Cron jobs', href: cronJobs.index(), icon: Clock },
];

const usageNavItem: NavItem = {
    title: 'Usage',
    href: usage.index(),
    icon: BarChart3,
};

const accountBackupsNavItem: NavItem = {
    title: 'Backups',
    href: accountBackups.index(),
    icon: ArchiveRestore,
};

const myAccountNavItem: NavItem = {
    title: 'Your accounts',
    href: accounts.mine(),
    icon: UserRound,
};

const accountsNavItem: NavItem = {
    title: 'Customer accounts',
    href: accounts.index(),
    icon: Building2,
};

const packagesNavItem: NavItem = {
    title: 'Packages',
    href: packages.index(),
    icon: PackageIcon,
};

const nodesNavItem: NavItem = {
    title: 'Nodes',
    href: nodes.index(),
    icon: Server,
};

const nodeBackupsNavItem: NavItem = {
    title: 'Node backups',
    href: backups.index(),
    icon: Archive,
};

const rolesNavItem: NavItem = {
    title: 'Roles',
    href: roles.index(),
    icon: ShieldCheck,
};

const auditLogNavItem: NavItem = {
    title: 'Audit log',
    href: auditLog.index(),
    icon: ScrollText,
};

export function AppSidebar() {
    const { auth } = usePage().props;

    const accountItems = auth.has_any_account_membership
        ? [usageNavItem, accountBackupsNavItem, myAccountNavItem]
        : [usageNavItem];

    // A delegated node admin sees Nodes only, scoped server-side to just their own granted
    // node(s); Accounts and node backups stay platform-admin-only.
    const adminItems = auth.is_provider_admin
        ? [
              accountsNavItem,
              packagesNavItem,
              nodesNavItem,
              nodeBackupsNavItem,
              rolesNavItem,
          ]
        : auth.has_node_admin_grants
          ? [nodesNavItem]
          : [];

    if (auth.can_view_audit_log) {
        adminItems.push(auditLogNavItem);
    }

    const groups: NavGroup[] = [
        {
            items: [
                { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
                { title: 'Tools', href: tools.index(), icon: Wrench },
            ],
        },
        { label: 'Hosting', items: hostingNavItems },
        { label: 'Your account', items: accountItems },
        ...(adminItems.length > 0
            ? [{ label: 'Administration', items: adminItems }]
            : []),
        {
            label: 'Help',
            items: [
                { title: 'Documentation', href: docs.index(), icon: BookOpen },
            ],
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {groups.map((group) => (
                    <NavMain
                        key={group.label ?? 'start'}
                        label={group.label}
                        items={group.items}
                    />
                ))}
            </SidebarContent>

            <SidebarFooter>
                <AccountPanel />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
