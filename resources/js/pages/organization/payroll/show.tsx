import { Head } from '@inertiajs/react';
import { PayrollShowContent } from '@/features/organization/payroll/show';
import type { PayrollShowProps } from '@/features/organization/payroll/types';

export default function PayrollShow(props: PayrollShowProps) {
    return (
        <>
            <Head title={`${props.period.name} · Crew`} />
            <PayrollShowContent {...props} />
        </>
    );
}
