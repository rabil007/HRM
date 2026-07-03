import { Head } from '@inertiajs/react';
import { ContractsEmployeeContent } from '@/features/organization/contracts/employee/contracts-employee-content';
import type { ContractEmployeeBrowseProps } from '@/features/organization/contracts/types';

export default function ContractEmployeeBrowse(
    props: ContractEmployeeBrowseProps,
) {
    return (
        <>
            <Head title={`Contracts — ${props.employee.name}`} />
            <ContractsEmployeeContent {...props} />
        </>
    );
}
