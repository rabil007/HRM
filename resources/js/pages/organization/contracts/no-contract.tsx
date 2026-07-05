import { Head } from '@inertiajs/react';
import { ContractsNoContractContent } from '@/features/organization/contracts/contracts-no-contract-content';
import type { NoContractIndexProps } from '@/features/organization/contracts/types';

export default function ContractsNoContract(props: NoContractIndexProps) {
    return (
        <>
            <Head title="No Contract Employees" />
            <ContractsNoContractContent {...props} />
        </>
    );
}
