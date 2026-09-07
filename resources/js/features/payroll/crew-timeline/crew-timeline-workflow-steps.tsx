import {
    Check,
    FileSpreadsheet,
    FileText,
    Send,
    ShieldCheck,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { CrewTimelinePreparationStatus } from './types';

type StepState = 'complete' | 'current' | 'upcoming';

const STEPS: {
    key: string;
    label: string;
    hint: string;
    icon: ComponentType<{ className?: string }>;
}[] = [
    {
        key: 'prepared',
        label: 'Prepared',
        hint: 'Draft created',
        icon: FileText,
    },
    {
        key: 'submitted',
        label: 'Submitted',
        hint: 'Sent for approval',
        icon: Send,
    },
    {
        key: 'approved',
        label: 'Approved',
        hint: 'Crewing signed off',
        icon: ShieldCheck,
    },
    {
        key: 'applied',
        label: 'Applied',
        hint: 'Written to timesheets',
        icon: FileSpreadsheet,
    },
];

function reachedIndex(status: CrewTimelinePreparationStatus | string): number {
    switch (status) {
        case 'applied':
            return 3;
        case 'approved':
            return 2;
        case 'submitted':
            return 1;
        default:
            return 0;
    }
}

export function CrewTimelineWorkflowSteps({
    status,
    isReturned,
}: {
    status: CrewTimelinePreparationStatus | string;
    isReturned: boolean;
}) {
    const reached = reachedIndex(status);

    return (
        <Card className="glass-card">
            <CardContent className="p-5">
                <ol className="flex flex-col gap-4 sm:flex-row sm:items-start">
                    {STEPS.map((step, index) => {
                        const state: StepState =
                            index < reached
                                ? 'complete'
                                : index === reached
                                  ? 'current'
                                  : 'upcoming';
                        const Icon = step.icon;
                        const showReturned =
                            isReturned && step.key === 'submitted';

                        return (
                            <li
                                key={step.key}
                                className="flex flex-1 items-start gap-3 sm:flex-col sm:items-center sm:gap-2"
                            >
                                {/* Connector + circle row on desktop */}
                                <div className="flex w-full flex-col items-center gap-0 sm:flex-row">
                                    {/* Left connector */}
                                    {index > 0 ? (
                                        <div
                                            className={cn(
                                                'hidden h-px flex-1 sm:block',
                                                index <= reached
                                                    ? 'bg-emerald-500/50'
                                                    : 'bg-border/60',
                                            )}
                                        />
                                    ) : (
                                        <div className="hidden flex-1 sm:block" />
                                    )}

                                    {/* Step circle */}
                                    <div className="relative shrink-0">
                                        {state === 'current' && (
                                            <span
                                                className={cn(
                                                    'absolute inset-0 -m-1 animate-ping rounded-full opacity-30',
                                                    showReturned
                                                        ? 'bg-amber-400'
                                                        : 'bg-primary',
                                                )}
                                            />
                                        )}
                                        <div
                                            className={cn(
                                                'relative flex h-9 w-9 items-center justify-center rounded-full border-2 transition-colors',
                                                state === 'complete' &&
                                                    'border-emerald-500/60 bg-emerald-500/20 text-emerald-600 dark:text-emerald-300',
                                                state === 'current' &&
                                                    !showReturned &&
                                                    'border-primary/60 bg-primary/20 text-primary',
                                                state === 'current' &&
                                                    showReturned &&
                                                    'border-amber-500/60 bg-amber-500/20 text-amber-600 dark:text-amber-300',
                                                state === 'upcoming' &&
                                                    'border-border/50 bg-muted/20 text-muted-foreground/50',
                                            )}
                                        >
                                            {state === 'complete' ? (
                                                <Check className="h-4 w-4" />
                                            ) : (
                                                <Icon className="h-4 w-4" />
                                            )}
                                        </div>
                                    </div>

                                    {/* Right connector */}
                                    {index < STEPS.length - 1 ? (
                                        <div
                                            className={cn(
                                                'hidden h-px flex-1 sm:block',
                                                index < reached
                                                    ? 'bg-emerald-500/50'
                                                    : 'bg-border/60',
                                            )}
                                        />
                                    ) : (
                                        <div className="hidden flex-1 sm:block" />
                                    )}
                                </div>

                                {/* Label block */}
                                <div className="min-w-0 pb-1 text-left sm:text-center">
                                    <p
                                        className={cn(
                                            'text-sm font-semibold',
                                            state === 'upcoming' &&
                                                'text-muted-foreground/50',
                                            state === 'complete' &&
                                                'text-emerald-700 dark:text-emerald-300',
                                        )}
                                    >
                                        {showReturned ? 'Returned' : step.label}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {showReturned
                                            ? 'Needs a new version'
                                            : step.hint}
                                    </p>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            </CardContent>
        </Card>
    );
}
