import type { ReactElement, ReactNode } from 'react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { tabs as dsTabs } from '@/lib/design-system';
import { cn } from '@/lib/utils';
import type { EmployeeProfileTabItem } from '@/features/organization/employees/profile/employee-profile-tabs';
import type { EmployeeTab } from '@/pages/organization/employee-page.types';

type EmployeeProfileShellProps = {
    activeTab: EmployeeTab;
    onTabChange: (tab: EmployeeTab) => void;
    tabs: EmployeeProfileTabItem[];
    actionBar?: ReactNode;
    header?: ReactNode;
    children: ReactNode;
};

export function EmployeeProfileShell({
    activeTab,
    onTabChange,
    tabs,
    actionBar,
    header,
    children,
}: EmployeeProfileShellProps): ReactElement {
    return (
        <>
            {actionBar}
            {header}

            <div id="employee-tabs" className="space-y-4">
                <Tabs
                    value={activeTab}
                    onValueChange={(value) => onTabChange(value as EmployeeTab)}
                    className="w-full"
                >
                    <div className="hide-scrollbar overflow-x-auto">
                        <TabsList
                            className={cn(dsTabs.list, 'min-w-full flex-nowrap')}
                        >
                            {tabs.map((tab) => (
                                <TabsTrigger
                                    key={tab.id}
                                    value={tab.id}
                                    className={cn(dsTabs.trigger, 'group')}
                                >
                                    {tab.label}
                                    {tab.count !== null && (
                                        <span className="ml-1.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-muted px-1 text-[10px] font-bold tabular-nums text-muted-foreground group-data-[state=active]:bg-primary/20 group-data-[state=active]:text-primary">
                                            {tab.count}
                                        </span>
                                    )}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </div>

                    <div>{children}</div>
                </Tabs>
            </div>
        </>
    );
}
