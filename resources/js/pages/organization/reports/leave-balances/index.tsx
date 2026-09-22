import { Head } from '@inertiajs/react';
import { LeaveBalanceReportContent } from '@/features/reports/leave-balances/content';
import type { LeaveBalanceReportProps } from '@/features/reports/leave-balances/types';

export default function LeaveBalanceReportIndex(
    props: LeaveBalanceReportProps,
) {
    return (
        <>
            <Head title="Leave Balance Report" />
            <LeaveBalanceReportContent {...props} />
        </>
    );
}
