import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import {
    employeeProfileTabPropsMissing,
    isEmployeeProfileTabLoading,
} from '@/features/organization/employees/profile/employee-profile-tab-props';
import type { EmployeeTab } from '@/pages/organization/employee-page.types';

/**
 * Loads optional Inertia props for the active employee profile tab on demand.
 */
export function useEmployeeProfileTabProps({
    activeTab,
    isCreateMode,
    pageProps,
}: {
    activeTab: EmployeeTab;
    isCreateMode: boolean;
    pageProps: Record<string, unknown>;
}): boolean {
    const inFlightKeyRef = useRef<string | null>(null);
    const missing = employeeProfileTabPropsMissing(activeTab, pageProps);
    const missingKey = missing.join(',');

    useEffect(() => {
        if (isCreateMode || missingKey === '') {
            inFlightKeyRef.current = null;

            return;
        }

        const requestKey = `${activeTab}:${missingKey}`;

        if (inFlightKeyRef.current === requestKey) {
            return;
        }

        inFlightKeyRef.current = requestKey;

        router.reload({
            only: missingKey.split(','),
            onFinish: () => {
                if (inFlightKeyRef.current === requestKey) {
                    inFlightKeyRef.current = null;
                }
            },
        });
    }, [activeTab, isCreateMode, missingKey]);

    return isEmployeeProfileTabLoading(activeTab, pageProps, isCreateMode);
}
