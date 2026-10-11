import { Head } from '@inertiajs/react';
import { RecruitmentReportContent } from '@/features/reports/recruitment/content';
import type { RecruitmentReportProps } from '@/features/reports/recruitment/types';

export default function RecruitmentReportIndex(props: RecruitmentReportProps) {
    return (
        <>
            <Head title="Recruitment Report" />
            <RecruitmentReportContent {...props} />
        </>
    );
}
