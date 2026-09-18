import { Link } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, FilePenLine, Info } from 'lucide-react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type {
    CorrectionsSummary,
    CrewAssignmentWarning,
} from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';
import { show as showCorrection } from '@/routes/organization/crew-movement-corrections';

export function CrewAssignmentAttentionCard({
    warnings,
    corrections,
}: {
    warnings: CrewAssignmentWarning[];
    corrections?: CorrectionsSummary;
}): ReactElement | null {
    const pendingCorrections = corrections?.pending ?? [];
    const totalCount = warnings.length + pendingCorrections.length;

    if (totalCount === 0) {
        return null;
    }

    const hasCritical = warnings.some((w) => w.severity === 'critical');

    return (
        <Card
            className={cn(
                'border shadow-xs',
                hasCritical
                    ? 'border-destructive/30 bg-destructive/3 dark:border-destructive/30 dark:bg-destructive/5'
                    : 'border-amber-500/30 bg-amber-500/3 dark:border-amber-500/30 dark:bg-amber-500/5',
            )}
        >
            <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <AlertTriangle
                            className={cn(
                                'size-4',
                                hasCritical
                                    ? 'text-destructive'
                                    : 'text-amber-600 dark:text-amber-400',
                            )}
                            aria-hidden="true"
                        />
                        <CardTitle className="text-sm font-semibold tracking-wide uppercase">
                            Needs Attention
                        </CardTitle>
                    </div>
                    <Badge
                        variant={hasCritical ? 'destructive' : 'warning'}
                        className="text-[10px]"
                    >
                        {totalCount} {totalCount === 1 ? 'item' : 'items'}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="space-y-2.5 pt-3.5">
                {/* Pending movement corrections */}
                {pendingCorrections.map((correction) => (
                    <Link
                        key={correction.id}
                        href={showCorrection.url(correction.id)}
                        className="group flex items-start gap-2.5 rounded-lg border border-amber-500/25 bg-background/80 p-2.5 transition-colors hover:border-amber-500/50 hover:bg-background"
                    >
                        <FilePenLine className="mt-0.5 size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-xs font-semibold text-foreground group-hover:underline">
                                    {correction.phase
                                        ? `${correction.phase.phase_code.toUpperCase()} · ${correction.phase.phase_label}`
                                        : 'Movement Correction'}
                                </p>
                                <span className="text-[10px] font-medium text-amber-700 dark:text-amber-300">
                                    Pending Review
                                </span>
                            </div>
                            <p className="mt-0.5 text-[11px] text-muted-foreground">
                                {correction.field_count} field
                                {correction.field_count > 1 ? 's' : ''} proposed
                                for correction
                            </p>
                        </div>
                    </Link>
                ))}

                {/* Assignment warnings */}
                {warnings.map((warning, idx) => {
                    const isCritical = warning.severity === 'critical';
                    const isWarning = warning.severity === 'warning';

                    return (
                        <div
                            key={`${warning.code}-${idx}`}
                            className={cn(
                                'flex items-start gap-2.5 rounded-lg border p-2.5',
                                isCritical
                                    ? 'border-destructive/30 bg-background/80'
                                    : isWarning
                                      ? 'border-amber-500/20 bg-background/80'
                                      : 'border-border/60 bg-background/60',
                            )}
                        >
                            {isCritical ? (
                                <AlertCircle className="mt-0.5 size-3.5 shrink-0 text-destructive" />
                            ) : isWarning ? (
                                <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                            ) : (
                                <Info className="mt-0.5 size-3.5 shrink-0 text-sky-600 dark:text-sky-400" />
                            )}
                            <div className="min-w-0 flex-1">
                                <p
                                    className={cn(
                                        'text-xs font-semibold',
                                        isCritical
                                            ? 'text-destructive'
                                            : isWarning
                                              ? 'text-amber-900 dark:text-amber-100'
                                              : 'text-foreground',
                                    )}
                                >
                                    {warning.label}
                                </p>
                                <p className="mt-0.5 text-[11px] text-muted-foreground">
                                    {warning.message}
                                </p>
                            </div>
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}
