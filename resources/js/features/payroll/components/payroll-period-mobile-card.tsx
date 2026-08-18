import { show } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import { MobileRecordCard } from '@/components/mobile-record-list';
import { PayrollPeriodStatusBadge } from '@/features/payroll/components/payroll-period-status-badge';
import { payrollPeriodMobileCardModel } from '@/features/payroll/lib/payroll-period-mobile-card';
import { formatDisplayDate } from '@/lib/format-date';
import type { PayrollPeriodListItem } from '../types';

export function PayrollPeriodMobileCard({
    period,
    canOpen,
}: {
    period: PayrollPeriodListItem;
    canOpen: boolean;
}) {
    const model = payrollPeriodMobileCardModel(period, canOpen);
    const href = canOpen ? show.url(period.id) : undefined;

    return (
        <MobileRecordCard
            title={model.title}
            subtitle={model.categoryLabel}
            meta={[
                `${formatDisplayDate(period.start_date)} – ${formatDisplayDate(period.end_date)}`,
                model.workflowLine,
            ]}
            status={
                <PayrollPeriodStatusBadge
                    status={model.status}
                    label={model.statusLabel}
                />
            }
            href={href}
            primaryAction={href ? { label: 'Open', href } : undefined}
        />
    );
}
