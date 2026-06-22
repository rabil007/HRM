import { Head } from '@inertiajs/react';
import { CrewPayrollContent } from '@/features/organization/crew-payroll';
import type { CrewPayrollBoardProps } from '@/features/organization/crew-payroll/types';

export default function CrewPayrollIndex(props: CrewPayrollBoardProps) {
    return (
        <>
            <Head title="Crew Payroll" />
            <CrewPayrollContent {...props} />
        </>
    );
}
