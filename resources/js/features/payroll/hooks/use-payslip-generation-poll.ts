import { usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from '@/lib/toast';
import type { PayslipSummary, PayrollPeriodStatus } from '../types';

const PAYSLIP_POLL_PROPS = [
    'payslip_summary',
    'payroll_records',
    'payroll_records_pagination',
    'payroll_records_monthly',
    'payroll_records_monthly_pagination',
    'flash',
] as const;

export function usePayslipGenerationPoll({
    periodStatus,
    payslipSummary,
}: {
    periodStatus: PayrollPeriodStatus;
    payslipSummary: PayslipSummary | null;
}): { isLiveUpdating: boolean } {
    const pendingCount = payslipSummary?.pending;
    const totalCount = payslipSummary?.total;
    const previousPending = useRef(pendingCount);

    const isFinalizedPeriod =
        periodStatus === 'approved' || periodStatus === 'paid';
    const shouldPoll =
        isFinalizedPeriod &&
        totalCount !== undefined &&
        pendingCount !== undefined &&
        totalCount > 0 &&
        pendingCount > 0;

    const { start, stop } = usePoll(
        2500,
        {
            only: [...PAYSLIP_POLL_PROPS],
        },
        {
            autoStart: false,
        },
    );

    useEffect(() => {
        if (!shouldPoll) {
            stop();

            return;
        }

        start();

        return () => {
            stop();
        };
    }, [shouldPoll, start, stop]);

    useEffect(() => {
        const previousPendingCount = previousPending.current;

        if (
            previousPendingCount !== undefined &&
            previousPendingCount > 0 &&
            pendingCount === 0 &&
            totalCount !== undefined &&
            totalCount > 0
        ) {
            toast.success('All payslips generated.');
        }

        previousPending.current = pendingCount;
    }, [pendingCount, totalCount]);

    return {
        isLiveUpdating: shouldPoll,
    };
}
