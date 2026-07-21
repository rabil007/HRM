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
                <ol className="flex flex-col gap-4 sm:flex-row sm:items-center">
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
                                className="flex flex-1 items-center gap-3"
                            >
                                <div
                                    className={cn(
                                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-full border transition-colors',
                                        state === 'complete' &&
                                            'border-emerald-500/40 bg-emerald-500/15 text-emerald-600 dark:text-emerald-300',
                                        state === 'current' &&
                                            !showReturned &&
                                            'border-primary/40 bg-primary/15 text-primary',
                                        state === 'current' &&
                                            showReturned &&
                                            'border-amber-500/40 bg-amber-500/15 text-amber-600 dark:text-amber-300',
                                        state === 'upcoming' &&
                                            'border-border/60 bg-muted/30 text-muted-foreground',
                                    )}
                                >
                                    {state === 'complete' ? (
                                        <Check className="h-5 w-5" />
                                    ) : (
                                        <Icon className="h-5 w-5" />
                                    )}
                                </div>
                                <div className="min-w-0">
                                    <p
                                        className={cn(
                                            'text-sm font-semibold',
                                            state === 'upcoming' &&
                                                'text-muted-foreground',
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
                                {index < STEPS.length - 1 ? (
                                    <div
                                        className={cn(
                                            'mx-1 hidden h-px flex-1 sm:block',
                                            index < reached
                                                ? 'bg-emerald-500/40'
                                                : 'bg-border/60',
                                        )}
                                    />
                                ) : null}
                            </li>
                        );
                    })}
                </ol>
            </CardContent>
        </Card>
    );
}
