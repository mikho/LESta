import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};

export type LegalDetails = {
    company_name: string | null;
    registration_number: string | null;
    vat_number: string | null;
    address: string | null;
    contact_email: string | null;
    privacy_email: string | null;
    governing_law: string | null;
    updated_at: string | null;
};

export type SidebarAccount = {
    name: string;
    package: string;
    primary_domain: string | null;
    previous_sign_in: string | null;
};
