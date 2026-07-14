import { useCallback, useEffect, useState } from 'react';
import type { EmployeeTab } from '@/pages/organization/employee-page.types';

const EMPLOYEE_PAGE_TAB_HASH_KEYS: Partial<Record<string, EmployeeTab>> = {
    '#contract': 'contract',
    '#salary-revisions': 'salary_revisions',
    '#documents': 'documents',
    '#education': 'education',
    '#work-experience': 'work_experience',
    '#vaccination': 'vaccination',
    '#languages': 'languages',
    '#training': 'training',
    '#sea-service': 'sea_service',
};

export function initialEmployeeTabFromLocation(): EmployeeTab {
    if (typeof window === 'undefined') {
        return 'personal';
    }

    return EMPLOYEE_PAGE_TAB_HASH_KEYS[window.location.hash] ?? 'personal';
}

export function useEmployeeProfileTabs(isCreateMode: boolean): {
    activeTab: EmployeeTab;
    setActiveTab: (tab: EmployeeTab) => void;
    handleTabChange: (tab: EmployeeTab) => void;
} {
    const [activeTab, setActiveTab] = useState<EmployeeTab>(
        initialEmployeeTabFromLocation,
    );

    useEffect(() => {
        if (isCreateMode || typeof window === 'undefined') {
            return;
        }

        const syncFromHash = (): void => {
            const fromHash = EMPLOYEE_PAGE_TAB_HASH_KEYS[window.location.hash];

            if (fromHash) {
                setActiveTab(fromHash);
            }
        };

        syncFromHash();
        window.addEventListener('hashchange', syncFromHash);

        return () => window.removeEventListener('hashchange', syncFromHash);
    }, [isCreateMode]);

    const handleTabChange = useCallback(
        (tab: EmployeeTab) => {
            setActiveTab(tab);

            if (isCreateMode || typeof window === 'undefined') {
                return;
            }

            const hashEntry = Object.entries(EMPLOYEE_PAGE_TAB_HASH_KEYS).find(
                ([, value]) => value === tab,
            );

            if (hashEntry) {
                window.history.replaceState(null, '', hashEntry[0]);
            } else if (tab === 'personal') {
                window.history.replaceState(
                    null,
                    '',
                    window.location.pathname + window.location.search,
                );
            }
        },
        [isCreateMode],
    );

    return { activeTab, setActiveTab, handleTabChange };
}
