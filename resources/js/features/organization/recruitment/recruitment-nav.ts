import type { LucideIcon } from 'lucide-react';
import {
    BadgeCheck,
    ClipboardList,
    History,
    UserCheck,
    Users,
} from 'lucide-react';

export type RecruitmentSubmodule = {
    key: string;
    title: string;
    href: string;
    permission: string;
    icon: LucideIcon;
    available: boolean;
};

/**
 * Authoritative catalog of Recruitment module submodules.
 * Submodules marked available=false are modeled for architecture readiness
 * but are strictly excluded from UI navigation until implemented.
 */
export const RECRUITMENT_SUBMODULES: RecruitmentSubmodule[] = [
    {
        key: 'requirements',
        title: 'Requirements',
        href: '/organization/recruitment/requirements',
        permission: 'recruitment.requirements.view',
        icon: ClipboardList,
        available: true,
    },
    {
        key: 'candidates',
        title: 'Candidates',
        href: '/organization/recruitment/candidates',
        permission: 'recruitment.candidates.view',
        icon: Users,
        available: false,
    },
    {
        key: 'client-assessments',
        title: 'Client Assessments',
        href: '/organization/recruitment/client-assessments',
        permission: 'recruitment.assessments.view',
        icon: UserCheck,
        available: false,
    },
    {
        key: 'offers-joining',
        title: 'Offers & Joining',
        href: '/organization/recruitment/offers-joining',
        permission: 'recruitment.offers.view',
        icon: BadgeCheck,
        available: false,
    },
    {
        key: 'history',
        title: 'Recruitment History',
        href: '/organization/recruitment/history',
        permission: 'recruitment.history.view',
        icon: History,
        available: false,
    },
];

/**
 * Returns only the active, implemented submodules for navigation rendering.
 */
export function getActiveRecruitmentSubmodules(): RecruitmentSubmodule[] {
    return RECRUITMENT_SUBMODULES.filter((sub) => sub.available);
}
