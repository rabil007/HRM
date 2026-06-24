import { Head } from '@inertiajs/react';
import { WpsExportContent } from '@/features/payroll/wps/wps-export-content';
import type { WpsExportPageProps } from '@/features/payroll/wps/types';

export default function WpsExportPage(props: WpsExportPageProps) {
    return (
        <>
            <Head title="WPS export" />
            <WpsExportContent {...props} />
        </>
    );
}
