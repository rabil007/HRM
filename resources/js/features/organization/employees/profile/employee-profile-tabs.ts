import type {
    EmployeeProfileTabVisibility,
    EmployeeTab,
} from '@/pages/organization/employee-page.types';

export type EmployeeProfileTabItem = {
    id: EmployeeTab;
    label: string;
    count: number | null;
};

type BuildTabsInput = {
    employee_tabs: EmployeeProfileTabVisibility;
    counts: {
        contracts?: EmployeeProfileTabItem['count'];
        bank_accounts?: EmployeeProfileTabItem['count'];
        education_qualifications?: EmployeeProfileTabItem['count'];
        work_experiences?: EmployeeProfileTabItem['count'];
        vaccinations?: EmployeeProfileTabItem['count'];
        languages?: EmployeeProfileTabItem['count'];
        trainings?: EmployeeProfileTabItem['count'];
        sea_services?: EmployeeProfileTabItem['count'];
        documents?: EmployeeProfileTabItem['count'];
    };
};

export function buildEmployeeProfileTabs({
    employee_tabs,
    counts,
}: BuildTabsInput): EmployeeProfileTabItem[] {
    const list: EmployeeProfileTabItem[] = [
        { id: 'personal', label: 'Personal', count: null },
        { id: 'contract', label: 'Contract', count: counts.contracts ?? null },
        { id: 'bank', label: 'Bank', count: counts.bank_accounts ?? null },
        {
            id: 'education',
            label: 'Education',
            count: counts.education_qualifications ?? null,
        },
        {
            id: 'work_experience',
            label: 'Work experience',
            count: counts.work_experiences ?? null,
        },
        {
            id: 'vaccination',
            label: 'Vaccination',
            count: counts.vaccinations ?? null,
        },
        {
            id: 'languages',
            label: 'Languages',
            count: counts.languages ?? null,
        },
        { id: 'training', label: 'Training', count: counts.trainings ?? null },
        {
            id: 'sea_service',
            label: 'Sea Service',
            count: counts.sea_services ?? null,
        },
        {
            id: 'documents',
            label: 'Documents',
            count: counts.documents ?? null,
        },
    ];

    return list.filter((tab) => {
        switch (tab.id) {
            case 'personal':
                return employee_tabs.personal;
            case 'contract':
                return employee_tabs.contract;
            case 'bank':
                return employee_tabs.bank;
            case 'education':
                return employee_tabs.education ?? true;
            case 'work_experience':
                return employee_tabs.work_experience ?? true;
            case 'languages':
                return employee_tabs.languages ?? true;
            case 'documents':
                return employee_tabs.documents;
            case 'sea_service':
                return employee_tabs.sea_service;
            case 'vaccination':
                return employee_tabs.vaccination;
            case 'training':
                return employee_tabs.training;
            default:
                return true;
        }
    });
}
