import { Head } from '@inertiajs/react';
import { PayrollShowContent } from '@/features/payroll/show';
import type { PayrollShowProps } from '@/features/payroll/types';

export default function PayrollShow(props: PayrollShowProps) {
    return (
        <>
            <Head
                title={`${props.period.name} · ${props.period.payroll_category_label}`}
            />
            <PayrollShowContent {...props} />
        </>
    );
}
