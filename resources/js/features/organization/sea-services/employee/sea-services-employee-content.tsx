import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { SeaServicesBreadcrumbs } from '@/features/organization/sea-services/employee/sea-services-breadcrumbs';
import type { SeaServiceEmployeeBrowseProps } from '@/features/organization/sea-services/types';
import { buildEmployeeShowUrl } from '@/features/organization/employees/build-employee-show-url';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { EmployeeSeaServiceTab } from '@/pages/organization/_components/employee-sea-service-tab';

export function SeaServicesEmployeeContent({
    employee,
    sea_services,
    vessel_types,
    vessels,
    ranks,
    clients,
    template_fields,
    back,
    can,
}: SeaServiceEmployeeBrowseProps) {
    return (
        <Main>
            <SeaServicesBreadcrumbs
                items={[
                    { title: 'Sea Services', href: back.href },
                    { title: employee.name },
                ]}
            />

            <DetailsHeader
                title={
                    <EmployeeProfileLink
                        employeeId={employee.id}
                        className="hover:underline"
                    >
                        {employee.name}
                    </EmployeeProfileLink>
                }
                description={employee.employee_no}
                backHref={back.href}
                backLabel={back.label}
                actions={
                    <Button
                        variant="outline"
                        className="h-12 rounded-xl border-input bg-background/50 px-6 hover:bg-muted dark:border-white/5 dark:bg-white/5 dark:hover:bg-white/10"
                        asChild
                    >
                        <Link href={buildEmployeeShowUrl(employee.id)}>
                            <User className="mr-2 size-4" />
                            View profile
                        </Link>
                    </Button>
                }
            />

            <EmployeeSeaServiceTab
                employeeId={employee.id}
                sea_services={sea_services}
                vessel_types={vessel_types}
                vessels={vessels}
                ranks={ranks}
                clients={clients}
                employeeRankId={null}
                canManage={can.create || can.update}
                canCreate={can.create}
                canUpdate={can.update}
                canDelete={can.delete}
                canImport={can.import}
                templateFields={template_fields}
                standalone
            />
        </Main>
    );
}
