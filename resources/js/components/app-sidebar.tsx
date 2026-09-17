import { Link, usePage } from '@inertiajs/react';
import {
    Archive,
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
    Server,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
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
import accounts from '@/routes/accounts';
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
import usage from '@/routes/usage';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Domains',
        href: domains.index(),
        icon: Globe,
    },
    {
        title: 'DNS',
        href: dns.index(),
        icon: Network,
    },
    {
        title: 'Mail',
        href: mail.index(),
        icon: MailIcon,
    },
    {
        title: 'Databases',
        href: tenantDatabases.index(),
        icon: Database,
    },
    {
        title: 'Cron jobs',
        href: cronJobs.index(),
        icon: Clock,
    },
    {
        title: 'Usage',
        href: usage.index(),
        icon: BarChart3,
    },
    {
        title: 'Documentation',
        href: docs.index(),
        icon: BookOpen,
    },
];

const accountsNavItem: NavItem = {
    title: 'Accounts',
    href: accounts.index(),
    icon: Building2,
};

const nodesNavItem: NavItem = {
    title: 'Nodes',
    href: nodes.index(),
    icon: Server,
};

const backupsNavItem: NavItem = {
    title: 'Backups',
    href: backups.index(),
    icon: Archive,
};

const packagesNavItem: NavItem = {
    title: 'Packages',
    href: packages.index(),
    icon: PackageIcon,
};

const rolesNavItem: NavItem = {
    title: 'Roles',
    href: roles.index(),
    icon: ShieldCheck,
};

const myAccountNavItem: NavItem = {
    title: 'My account',
    href: accounts.mine(),
    icon: UserRound,
};

export function AppSidebar() {
    const { auth } = usePage().props;

    let items = mainNavItems;

    if (auth.is_provider_admin) {
        items = [
            ...items,
            accountsNavItem,
            nodesNavItem,
            backupsNavItem,
            packagesNavItem,
            rolesNavItem,
        ];
    } else if (auth.has_node_admin_grants) {
        // A delegated node admin sees Nodes only, scoped server-side to just their own
        // granted node(s) -- never Accounts or Backups, which stay platform-admin-only.
        items = [...items, nodesNavItem];
    }

    if (auth.has_any_account_membership) {
        // Independent of admin/node-admin capacity: a provider admin who also happens to hold a
        // real membership sees both the platform-wide Accounts list and their own account.
        items = [...items, myAccountNavItem];
    }

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
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
