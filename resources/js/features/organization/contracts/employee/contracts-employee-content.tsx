import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { ContractsBreadcrumbs } from '@/features/organization/contracts/employee/contracts-breadcrumbs';
import type { ContractEmployeeBrowseProps } from '@/features/organization/contracts/types';
import { buildEmployeeShowUrl } from '@/features/organization/employees/build-employee-show-url';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { EmployeeContractTab } from '@/pages/organization/_components/employee-contract-tab';

export function ContractsEmployeeContent({
    employee,
    contracts,
    template_contract_fields,
    back,
    can,
}: ContractEmployeeBrowseProps) {
    return (
        <Main>
            <ContractsBreadcrumbs
                items={[
                    { title: 'Contracts', href: back.href },
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
                subtitle={employee.employee_no}
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

            <EmployeeContractTab
                employeeId={employee.id}
                contracts={contracts}
                canCreate={can.create}
                canUpdate={can.update}
                canDelete={can.delete}
                templateContractFields={template_contract_fields}
            />
        </Main>
    );
}

