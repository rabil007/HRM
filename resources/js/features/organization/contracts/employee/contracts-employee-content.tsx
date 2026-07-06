import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { ContractsBreadcrumbs } from '@/features/organization/contracts/employee/contracts-breadcrumbs';
import type { ContractEmployeeBrowseProps } from '@/features/organization/contracts/types';
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
                title={employee.name}
                subtitle={employee.employee_no}
                backHref={back.href}
                backLabel={back.label}
                actions={
                    <Link
                        href={back.href}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        Back to contracts
                    </Link>
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
