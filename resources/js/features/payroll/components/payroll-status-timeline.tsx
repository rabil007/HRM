import { Ban, CheckCircle2 } from 'lucide-react';
import React from 'react';
import { cn } from '@/lib/utils';

export const PAYROLL_FLOW = [
    {
        status: 'draft',
        label: 'Draft',
        description: 'Pay run created',
    },
    {
        status: 'processing',
        label: 'Processing',
        description: 'Payroll generated',
    },
    {
        status: 'approved',
        label: 'Approved',
        description: 'Pay run approved',
    },
    {
        status: 'paid',
        label: 'Paid',
        description: 'Salaries disbursed',
    },
] as const;

export function PayrollStatusTimeline({
    status,
    approver,
}: {
    status: string;
    approver: { id: number; name: string } | null;
}) {
    const isCancelled = status === 'cancelled';
    const currentIndex = PAYROLL_FLOW.findIndex((s) => s.status === status);

    if (isCancelled) {
        return (
            <div className="relative mb-6 overflow-hidden rounded-2xl border border-destructive/20 bg-gradient-to-r from-destructive/5 via-destructive/3 to-background p-4">
                <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_left,_var(--tw-gradient-stops))] from-destructive/10 via-transparent to-transparent" />
                <div className="relative flex items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-destructive/30 bg-destructive/10 text-destructive shadow-inner">
                        <Ban className="h-5 w-5" />
                    </div>
                    <div>
                        <p className="text-sm font-bold text-destructive">
                            Pay Run Cancelled
                        </p>
                        <p className="text-xs text-muted-foreground/70">
                            This payroll period has been cancelled and cannot be
                            processed.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="relative mb-6 overflow-hidden rounded-2xl border border-border/40 bg-gradient-to-r from-muted/20 via-background to-background p-5 shadow-sm">
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_left,_var(--tw-gradient-stops))] from-primary/5 via-transparent to-transparent" />

            <div className="relative">
                <div className="flex items-start justify-between gap-2">
                    {PAYROLL_FLOW.map((step, index) => {
                        const isCompleted = index < currentIndex;
                        const isActive = index === currentIndex;
                        const isFuture = index > currentIndex;
                        const isLast = index === PAYROLL_FLOW.length - 1;

                        return (
                            <React.Fragment key={step.status}>
                                <div className="flex min-w-0 flex-1 flex-col items-center gap-2">
                                    <div
                                        className={cn(
                                            'relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 transition-all duration-500',
                                            isCompleted &&
                                                'border-emerald-500 bg-emerald-500 text-white shadow-lg shadow-emerald-500/30',
                                            isActive &&
                                                'scale-110 border-primary bg-primary text-primary-foreground shadow-lg shadow-primary/40',
                                            isFuture &&
                                                'border-border/40 bg-muted/30 text-muted-foreground/40',
                                        )}
                                    >
                                        {isCompleted ? (
                                            <CheckCircle2 className="h-5 w-5" />
                                        ) : isActive ? (
                                            <>
                                                <span className="absolute inset-0 animate-ping rounded-full bg-primary/30 duration-1000" />
                                                <span className="relative h-2.5 w-2.5 rounded-full bg-primary-foreground" />
                                            </>
                                        ) : (
                                            <span className="h-2 w-2 rounded-full bg-current" />
                                        )}
                                    </div>

                                    <div className="text-center">
                                        <p
                                            className={cn(
                                                'text-xs font-bold transition-colors duration-300',
                                                isCompleted &&
                                                    'text-emerald-600 dark:text-emerald-400',
                                                isActive && 'text-primary',
                                                isFuture &&
                                                    'text-muted-foreground/40',
                                            )}
                                        >
                                            {step.label}
                                        </p>
                                        <p
                                            className={cn(
                                                'mt-0.5 text-[10px] transition-colors duration-300',
                                                isActive
                                                    ? 'text-muted-foreground'
                                                    : 'text-muted-foreground/40',
                                            )}
                                        >
                                            {step.description}
                                        </p>
                                        {step.status === 'approved' &&
                                        isCompleted &&
                                        approver ? (
                                            <p className="mt-1 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">
                                                by {approver.name}
                                            </p>
                                        ) : null}
                                    </div>
                                </div>

                                {!isLast && (
                                    <div className="relative mt-5 h-0.5 flex-1 overflow-hidden rounded-full bg-border/30">
                                        <div
                                            className={cn(
                                                'h-full rounded-full transition-all duration-700',
                                                isCompleted
                                                    ? 'w-full bg-emerald-500'
                                                    : 'w-0',
                                            )}
                                        />
                                    </div>
                                )}
                            </React.Fragment>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
