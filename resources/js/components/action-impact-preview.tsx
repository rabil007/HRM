import { AlertTriangle, Check } from 'lucide-react';
import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';

export type ActionImpactSeverity = 'normal' | 'high' | 'destructive';

export type ActionImpactChange = {
    label: string;
    previous: string;
    next: string;
};

export type ActionImpactPreviewProps = {
    title?: string;
    subject?: string;
    currentState?: string;
    destinationState?: string;
    impacts?: string[];
    changes?: ActionImpactChange[];
    warning?: string;
    severity?: ActionImpactSeverity;
    compact?: boolean;
    movementTime?: string | null;
    className?: string;
};

function severityContainerClass(severity: ActionImpactSeverity): string {
    switch (severity) {
        case 'destructive':
            return 'border-destructive/35 bg-destructive/10';
        case 'high':
            return 'border-primary/35 bg-primary/5';
        default:
            return 'border-border/80 bg-muted/30';
    }
}

function severityTitleClass(severity: ActionImpactSeverity): string {
    switch (severity) {
        case 'destructive':
            return 'text-destructive';
        case 'high':
            return 'text-foreground';
        default:
            return 'text-foreground';
    }
}

function severityBodyClass(severity: ActionImpactSeverity): string {
    switch (severity) {
        case 'destructive':
            return 'text-destructive/90';
        case 'high':
            return 'text-muted-foreground';
        default:
            return 'text-muted-foreground';
    }
}

export function ActionImpactPreview({
    title = 'What will happen',
    subject,
    currentState,
    destinationState,
    impacts = [],
    changes = [],
    warning,
    severity = 'normal',
    compact = false,
    movementTime,
    className,
}: ActionImpactPreviewProps): ReactElement | null {
    const hasContext = Boolean(
        subject || currentState || destinationState || movementTime,
    );
    const hasImpacts = impacts.length > 0;
    const hasChanges = changes.length > 0;

    if (!hasContext && !hasImpacts && !hasChanges && !warning) {
        return null;
    }

    if (compact && !hasChanges && !warning) {
        return (
            <div
                className={cn(
                    'rounded-lg border p-3 text-sm',
                    severityContainerClass(severity),
                    className,
                )}
            >
                {subject ? (
                    <p className="font-medium text-foreground">{subject}</p>
                ) : null}
                {currentState ? (
                    <p className="mt-1 text-muted-foreground">{currentState}</p>
                ) : null}
                {movementTime ? (
                    <p className="mt-2 text-xs text-muted-foreground">
                        Movement time:{' '}
                        <span className="font-medium text-foreground">
                            {movementTime}
                        </span>
                    </p>
                ) : null}
                {hasImpacts ? (
                    <div className="mt-2 space-y-1">
                        <p
                            className={cn(
                                'text-xs font-semibold tracking-wide uppercase',
                                severity === 'destructive'
                                    ? 'text-destructive'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {title}
                        </p>
                        {impacts.map((item) => (
                            <p
                                key={item}
                                className="text-sm text-muted-foreground"
                            >
                                {item}
                            </p>
                        ))}
                    </div>
                ) : null}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'rounded-lg border p-3 text-sm',
                severityContainerClass(severity),
                className,
            )}
        >
            {subject ? (
                <p
                    className={cn(
                        'font-semibold',
                        severityTitleClass(severity),
                        subject.includes(' ')
                            ? 'whitespace-pre-line'
                            : undefined,
                    )}
                >
                    {subject}
                </p>
            ) : null}

            {(currentState || destinationState) && (
                <div className="mt-2 space-y-1">
                    {currentState ? (
                        <div className="flex flex-wrap gap-x-2">
                            <span className="text-muted-foreground">
                                Current:
                            </span>
                            <span className="font-medium text-foreground">
                                {currentState}
                            </span>
                        </div>
                    ) : null}
                    {destinationState ? (
                        <div className="flex flex-wrap gap-x-2">
                            <span className="text-muted-foreground">
                                Destination:
                            </span>
                            <span className="font-medium text-foreground">
                                {destinationState}
                            </span>
                        </div>
                    ) : null}
                </div>
            )}

            {movementTime ? (
                <p className="mt-2 text-xs text-muted-foreground">
                    Movement time:{' '}
                    <span className="font-medium text-foreground">
                        {movementTime}
                    </span>
                </p>
            ) : null}

            {hasChanges ? (
                <div className="mt-3 space-y-2">
                    {changes.map((change) => (
                        <div key={change.label} className="space-y-0.5">
                            <p className="font-medium text-foreground">
                                {change.label}
                            </p>
                            <p className={severityBodyClass(severity)}>
                                Previous: {change.previous}
                            </p>
                            <p className={severityBodyClass(severity)}>
                                New: {change.next}
                            </p>
                        </div>
                    ))}
                </div>
            ) : null}

            {hasImpacts ? (
                <div className="mt-3">
                    <p
                        className={cn(
                            'font-medium',
                            severityTitleClass(severity),
                        )}
                    >
                        {title}
                    </p>
                    <ul className="mt-2 space-y-1.5">
                        {impacts.map((item) => (
                            <li
                                key={item}
                                className={cn(
                                    'flex gap-2',
                                    severityBodyClass(severity),
                                )}
                            >
                                <Check
                                    className={cn(
                                        'mt-0.5 size-3.5 shrink-0',
                                        severity === 'destructive'
                                            ? 'text-destructive'
                                            : severity === 'high'
                                              ? 'text-primary'
                                              : 'text-muted-foreground',
                                    )}
                                    aria-hidden
                                />
                                <span>{item}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {warning ? (
                <div
                    className={cn(
                        'mt-3 flex gap-2 rounded-md border px-2.5 py-2 text-xs',
                        severity === 'destructive'
                            ? 'border-destructive/40 bg-destructive/15 text-destructive'
                            : 'border-amber-500/30 bg-amber-500/10 text-amber-900 dark:text-amber-100',
                    )}
                >
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                    <span>{warning}</span>
                </div>
            ) : null}
        </div>
    );
}
