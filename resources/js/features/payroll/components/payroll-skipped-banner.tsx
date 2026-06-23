import { AlertTriangle } from 'lucide-react';
import type { PayrollCategory, PayrollGenerationSummary } from '../types';

export function PayrollSkippedBanner({
    summary,
    payrollCategory,
}: {
    summary: PayrollGenerationSummary | null;
    payrollCategory: PayrollCategory;
}) {
    if (!summary || (summary.skipped_count === 0 && summary.errors.length === 0)) {
        return null;
    }

    const skipLabel =
        payrollCategory === 'crew' ? 'skipped (no timesheet)' : 'skipped (no attendance)';

    return (
        <div className="mb-4 space-y-3 rounded-2xl border border-amber-500/25 bg-amber-500/5 px-4 py-3.5">
            <div className="flex items-start gap-3">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                <div className="space-y-2 text-sm">
                    {summary.generated_count > 0 ? (
                        <p className="font-semibold text-foreground">
                            Generated payroll for {summary.generated_count} employee
                            {summary.generated_count === 1 ? '' : 's'}.
                        </p>
                    ) : null}
                    {summary.skipped_count > 0 ? (
                        <div>
                            <p className="font-medium text-amber-700 dark:text-amber-200">
                                {summary.skipped_count} employee
                                {summary.skipped_count === 1 ? '' : 's'} {skipLabel}
                            </p>
                            <ul className="mt-1 list-inside list-disc text-muted-foreground">
                                {summary.skipped_employees.map((employee) => (
                                    <li key={employee.id}>
                                        {employee.name}
                                        {employee.employee_no ? ` · ${employee.employee_no}` : ''}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                    {summary.errors.length > 0 ? (
                        <div>
                            <p className="font-medium text-destructive">Calculation errors</p>
                            <ul className="mt-1 list-inside list-disc text-muted-foreground">
                                {summary.errors.map((error) => (
                                    <li key={error.employee_id}>{error.message}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
