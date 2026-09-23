import type { ReactNode } from 'react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { RecruitmentBreadcrumbs } from './recruitment-breadcrumbs';
import type { RecruitmentBreadcrumbItem } from './recruitment-breadcrumbs';

export type RecruitmentModuleShellProps = {
    title: ReactNode;
    description?: string;
    kicker?: string;
    right?: ReactNode;
    breadcrumbs?: RecruitmentBreadcrumbItem[];
    children: ReactNode;
};

/**
 * Reusable shell for Recruitment pages providing standard breadcrumbs,
 * header hierarchy, and layout structure across current and future submodules.
 */
export function RecruitmentModuleShell({
    title,
    description,
    kicker = 'Recruitment',
    right,
    breadcrumbs = [],
    children,
}: RecruitmentModuleShellProps) {
    return (
        <Main>
            <RecruitmentBreadcrumbs items={breadcrumbs} />

            <PageHeader
                kicker={kicker}
                title={title}
                description={description}
                right={right}
            />

            {children}
        </Main>
    );
}
