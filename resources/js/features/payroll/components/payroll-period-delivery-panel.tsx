import type {
    CrewPayrollPermissions,
    PayrollPeriod,
    PayrollPeriodStatus,
    PayslipSummary,
    WpsPreview,
} from '../types';
import { PayslipDeliveryCard } from './payslip-delivery-card';
import { WpsDeliveryCard } from './wps-delivery-card';

const PAYSLIP_DELIVERY_STATUSES: PayrollPeriodStatus[] = ['approved', 'paid'];

export function canShowPayslipDeliveryPanel(
    period: PayrollPeriod,
    payslipSummary: PayslipSummary,
): boolean {
    return (
        PAYSLIP_DELIVERY_STATUSES.includes(
            period.status as PayrollPeriodStatus,
        ) && payslipSummary.total > 0
    );
}

export function PayrollPeriodDeliveryPanel({
    period,
    payslip_summary,
    wps_preview,
    permissions,
    selectedWpsRecordIds = null,
    isPayslipGenerationLive = false,
}: {
    period: PayrollPeriod;
    payslip_summary: PayslipSummary;
    wps_preview: WpsPreview | null;
    permissions: CrewPayrollPermissions;
    selectedWpsRecordIds?: number[] | null;
    isPayslipGenerationLive?: boolean;
}) {
    const showPayslipsCard = canShowPayslipDeliveryPanel(
        period,
        payslip_summary,
    );
    const showWpsCard = wps_preview !== null;

    if (!showPayslipsCard && !showWpsCard) {
        return null;
    }

    return (
        <div
            className={
                showPayslipsCard && showWpsCard
                    ? 'mb-6 grid gap-4 md:grid-cols-2'
                    : 'mb-6'
            }
        >
            {showPayslipsCard ? (
                <PayslipDeliveryCard
                    periodId={period.id}
                    summary={payslip_summary}
                    canEmail={permissions.payslips_email}
                    isLiveUpdating={isPayslipGenerationLive}
                />
            ) : null}

            {showWpsCard && wps_preview ? (
                <WpsDeliveryCard
                    periodId={period.id}
                    preview={wps_preview}
                    canExport={permissions.wps_export}
                    selectedRecordIds={selectedWpsRecordIds}
                />
            ) : null}
        </div>
    );
}
