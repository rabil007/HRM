import { Head } from '@inertiajs/react';
import { AttendanceOverviewContent } from '@/features/attendance/overview/attendance-overview-content';
import type { AttendanceOverviewProps } from '@/features/attendance/overview/attendance-overview-content';

export default function AttendanceOverview(props: AttendanceOverviewProps) {
    return (
        <>
            <Head title="Attendance Overview" />
            <AttendanceOverviewContent {...props} />
        </>
    );
}
