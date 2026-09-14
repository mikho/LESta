import { Link, usePage } from '@inertiajs/react';
import {
    Archive,
    BarChart3,
    BookOpen,
    Building2,
    Clock,
    Database,
    FolderGit2,
    Globe,
    LayoutGrid,
    Network,
    Server,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
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
import domains from '@/routes/domains';
import nodes from '@/routes/nodes';
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

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;

    let items = mainNavItems;

    if (auth.is_provider_admin) {
        items = [...items, accountsNavItem, nodesNavItem, backupsNavItem];
    } else if (auth.has_node_admin_grants) {
        // A delegated node admin sees Nodes only, scoped server-side to just their own
        // granted node(s) -- never Accounts or Backups, which stay platform-admin-only.
        items = [...items, nodesNavItem];
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
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
