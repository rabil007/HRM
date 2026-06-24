import { Head } from '@inertiajs/react';
import type { WpsExportPageProps } from '@/features/payroll/wps/types';
import { WpsExportContent } from '@/features/payroll/wps/wps-export-content';

export default function WpsExportPage(props: WpsExportPageProps) {
    return (
        <>
            <Head title="WPS export" />
            <WpsExportContent {...props} />
        </>
    );
}
