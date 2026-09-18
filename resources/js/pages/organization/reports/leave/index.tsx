import { Head } from '@inertiajs/react';
import { LeaveReportContent } from '@/features/reports/leave/content';
import type { LeaveReportProps } from '@/features/reports/leave/types';

export default function LeaveReportIndex(props: LeaveReportProps) {
    return (
        <>
            <Head title="Leave Report" />
            <LeaveReportContent {...props} />
        </>
    );
}
