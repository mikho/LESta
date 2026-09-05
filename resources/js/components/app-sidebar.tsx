import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
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
import cronJobs from '@/routes/cron-jobs';
import dns from '@/routes/dns';
import domains from '@/routes/domains';
import nodes from '@/routes/nodes';
import tenantDatabases from '@/routes/tenant-databases';
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
];

const nodesNavItem: NavItem = {
    title: 'Nodes',
    href: nodes.index(),
    icon: Server,
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

    const items = auth.is_provider_admin
        ? [...mainNavItems, nodesNavItem]
        : mainNavItems;

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
