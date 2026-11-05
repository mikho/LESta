import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { LegalFooter } from '@/components/legal-footer';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <a
                href="#main-content"
                className="sr-only z-50 rounded-md bg-background px-4 py-2 text-sm font-medium shadow focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
            >
                Skip to content
            </a>
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <ImpersonationBanner />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div id="main-content" tabIndex={-1} className="outline-none">
                    {children}
                </div>
                <LegalFooter />
            </AppContent>
        </AppShell>
    );
}
