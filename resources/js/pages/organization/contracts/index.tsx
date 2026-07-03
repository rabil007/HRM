import { Head } from '@inertiajs/react';
import { ContractsContent } from '@/features/organization/contracts/contracts-content';
import type { ContractsIndexProps } from '@/features/organization/contracts/types';

export default function ContractsIndex(props: ContractsIndexProps) {
    return (
        <>
            <Head title="Contracts" />
            <ContractsContent {...props} />
        </>
    );
}
