import type {
    CrewPayrollPermissions,
    PayrollPeriod,
    WpsPreview,
} from '../types';
import { WpsDeliveryCard } from './wps-delivery-card';

export function PayrollPeriodDeliveryPanel({
    period,
    wps_preview,
    permissions,
    selectedWpsRecordIds = null,
}: {
    period: PayrollPeriod;
    wps_preview: WpsPreview | null;
    permissions: CrewPayrollPermissions;
    selectedWpsRecordIds?: number[] | null;
    payslip_summary?: unknown;
    isPayslipGenerationLive?: boolean;
}) {
    const showWpsCard = wps_preview !== null && permissions.wps_view;

    if (!showWpsCard || !wps_preview) {
        return null;
    }

    return (
        <div className="mb-6">
            <WpsDeliveryCard
                periodId={period.id}
                preview={wps_preview}
                canExport={permissions.wps_export}
                selectedRecordIds={selectedWpsRecordIds}
            />
        </div>
    );
}
