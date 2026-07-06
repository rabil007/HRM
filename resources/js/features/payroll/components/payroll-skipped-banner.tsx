import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    ChevronDown,
    ChevronUp,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import type { PayrollGenerationSummary } from '../types';

export function PayrollSkippedBanner({
    summary,
}: {
    summary: PayrollGenerationSummary | null;
}) {
    const [isDismissed, setIsDismissed] = useState(false);
    const [errorsExpanded, setErrorsExpanded] = useState(true);

    if (
        !summary ||
        (summary.skipped_count === 0 && summary.errors.length === 0)
    ) {
        return null;
    }

    if (isDismissed) {
        return null;
    }

    const skipLabel = 'skipped';

    return (
        <div className="relative mb-4 space-y-3 rounded-2xl border border-amber-500/25 bg-amber-500/5 px-4 py-3.5 pr-10">
            {/* Close button */}
            <button
                type="button"
                onClick={() => setIsDismissed(true)}
                className="absolute top-3 right-3 rounded-lg p-1 text-muted-foreground/60 transition-colors hover:bg-amber-500/10 hover:text-foreground"
                aria-label="Dismiss banner"
            >
                <X className="h-4 w-4" />
            </button>

            <div className="flex items-start gap-3">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                <div className="min-w-0 flex-1 space-y-3 text-sm">
                    {summary.generated_count > 0 ? (
                        <p className="font-semibold text-foreground">
                            Generated payroll for {summary.generated_count}{' '}
                            employee
                            {summary.generated_count === 1 ? '' : 's'}.
                        </p>
                    ) : null}
                    {summary.skipped_count > 0 ? (
                        <div>
                            <p className="font-medium text-amber-700 dark:text-amber-200">
                                {summary.skipped_count} employee
                                {summary.skipped_count === 1 ? '' : 's'}{' '}
                                {skipLabel}
                            </p>
                            <ul className="mt-1 list-inside list-disc text-muted-foreground">
                                {summary.skipped_employees.map((employee) => (
                                    <li key={employee.id}>
                                        {employee.name}
                                        {employee.employee_no
                                            ? ` · ${employee.employee_no}`
                                            : ''}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                    {summary.errors.length > 0 ? (
                        <div className="space-y-2">
                            <div className="flex items-center justify-between border-b border-destructive/10 pb-1.5">
                                <p className="flex items-center gap-1.5 font-medium text-destructive">
                                    Calculation errors
                                    <span className="rounded-full bg-destructive/10 px-1.5 py-0.5 text-xs font-semibold text-destructive">
                                        {summary.errors.length}
                                    </span>
                                </p>
                                <button
                                    type="button"
                                    onClick={() =>
                                        setErrorsExpanded(!errorsExpanded)
                                    }
                                    className="flex items-center gap-1 text-xs font-semibold text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    {errorsExpanded ? (
                                        <>
                                            Hide{' '}
                                            <ChevronUp className="h-3.5 w-3.5" />
                                        </>
                                    ) : (
                                        <>
                                            Show{' '}
                                            <ChevronDown className="h-3.5 w-3.5" />
                                        </>
                                    )}
                                </button>
                            </div>
                            {errorsExpanded && (
                                <div className="space-y-2 transition-all duration-300">
                                    {summary.errors.map((error) => (
                                        <div
                                            key={`${error.employee_id}-${error.field ?? 'general'}`}
                                            className="flex flex-col gap-3 rounded-xl border border-destructive/15 bg-background/60 p-3 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="min-w-0 space-y-1">
                                                <p className="font-semibold text-foreground">
                                                    {error.employee_name}
                                                    {error.employee_no ? (
                                                        <span className="ml-2 font-normal text-muted-foreground">
                                                            {error.employee_no}
                                                        </span>
                                                    ) : null}
                                                </p>
                                                {error.field_label ? (
                                                    <p className="text-xs font-semibold tracking-wide text-destructive/80 uppercase">
                                                        Missing:{' '}
                                                        {error.field_label}
                                                    </p>
                                                ) : null}
                                                <p className="text-muted-foreground">
                                                    {error.message}
                                                </p>
                                            </div>
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                                className="shrink-0 rounded-lg"
                                            >
                                                <Link href={error.employee_url}>
                                                    View employee
                                                    <ArrowUpRight className="ml-2 h-4 w-4" />
                                                </Link>
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
