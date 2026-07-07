import { usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
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
    tab,
    periodStatus,
    payslipSummary,
}: {
    tab: string;
    periodStatus: PayrollPeriodStatus;
    payslipSummary: PayslipSummary;
}): { isLiveUpdating: boolean } {
    const previousPending = useRef(payslipSummary.pending);
    const [isPolling, setIsPolling] = useState(false);

    const isFinalizedPeriod =
        periodStatus === 'approved' || periodStatus === 'paid';
    const shouldPoll =
        tab === 'payroll' &&
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
            setIsPolling(false);

            return;
        }

        start();
        setIsPolling(true);

        return () => {
            stop();
            setIsPolling(false);
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
        isLiveUpdating: shouldPoll && isPolling,
    };
}
