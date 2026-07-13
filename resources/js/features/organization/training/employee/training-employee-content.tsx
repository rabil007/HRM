import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { TrainingBreadcrumbs } from '@/features/organization/training/employee/training-breadcrumbs';
import type { TrainingEmployeeBrowseProps } from '@/features/organization/training/types';
import { buildEmployeeShowUrl } from '@/features/organization/employees/build-employee-show-url';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { EmployeeTrainingTab } from '@/pages/organization/_components/employee-training-tab';

export function TrainingEmployeeContent({
    employee,
    trainings,
    courses,
    countries,
    template_fields,
    back,
    can,
}: TrainingEmployeeBrowseProps) {
    return (
        <Main>
            <TrainingBreadcrumbs
                items={[
                    { title: 'Training', href: back.href },
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

            <EmployeeTrainingTab
                employeeId={employee.id}
                employeeName={employee.name}
                trainings={trainings}
                courses={courses}
                countries={countries}
                canCreate={can.create}
                canUpdate={can.update}
                canDelete={can.delete}
                canImport={can.import}
                templateFields={template_fields}
                standalone
                showBackFrom="employee-browse"
            />
        </Main>
    );
}
