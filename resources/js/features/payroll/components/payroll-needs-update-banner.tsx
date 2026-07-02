import { AlertTriangle } from 'lucide-react';
import type { PayrollNeedsUpdateReason } from '../types';

const reasonLabels: Record<PayrollNeedsUpdateReason, string> = {
    salary_inputs: 'Salary inputs were added, changed, or removed',
    timesheets: 'Timesheet data was updated',
    new_timesheets: 'New timesheets are ready for payroll',
};

export function PayrollNeedsUpdateBanner({
    reasons,
    onUpdate,
}: {
    reasons: PayrollNeedsUpdateReason[];
    onUpdate?: () => void;
}) {
    if (reasons.length === 0) {
        return null;
    }

    return (
        <div className="mb-4 flex flex-col gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/8 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                <div className="space-y-1 text-sm">
                    <p className="font-semibold text-amber-900 dark:text-amber-100">
                        Payroll is out of date
                    </p>
                    <ul className="list-inside list-disc text-muted-foreground">
                        {reasons.map((reason) => (
                            <li key={reason}>{reasonLabels[reason]}</li>
                        ))}
                    </ul>
                    <p className="text-muted-foreground">
                        Click <strong>Update payroll</strong> to refresh gross
                        and net amounts.
                    </p>
                </div>
            </div>
            {onUpdate ? (
                <button
                    type="button"
                    onClick={onUpdate}
                    className="shrink-0 rounded-xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-amber-600"
                >
                    Update payroll
                </button>
            ) : null}
        </div>
    );
}
