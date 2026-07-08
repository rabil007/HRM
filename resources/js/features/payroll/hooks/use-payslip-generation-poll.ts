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
] as const;

export function usePayslipGenerationPoll({
    periodStatus,
    payslipSummary,
}: {
    periodStatus: PayrollPeriodStatus;
    payslipSummary: PayslipSummary;
}): { isLiveUpdating: boolean } {
    const previousPending = useRef(payslipSummary.pending);

    const isFinalizedPeriod =
        periodStatus === 'approved' || periodStatus === 'paid';
    const shouldPoll =
        isFinalizedPeriod &&
        payslipSummary.total > 0 &&
        payslipSummary.pending > 0;

    const { start, stop } = usePoll(
        2500,
        {
            only: [...PAYSLIP_POLL_PROPS],
            preserveScroll: true,
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
            previousPendingCount > 0 &&
            payslipSummary.pending === 0 &&
            payslipSummary.total > 0
        ) {
            toast.success('All payslips generated.');
        }

        previousPending.current = payslipSummary.pending;
    }, [payslipSummary.pending, payslipSummary.total]);

    return {
        isLiveUpdating: shouldPoll,
    };
}
