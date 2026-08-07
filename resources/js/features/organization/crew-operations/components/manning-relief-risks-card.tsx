import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { CrewOperationsManningReliefRisk } from '@/features/organization/crew-operations/types';
import { formatDisplayDate } from '@/lib/format-date';
import { projectedManning as projectedManningRoute } from '@/routes/organization/crew-operations';

function riskVariant(
    kind: CrewOperationsManningReliefRisk['kind'],
    risk: string,
): 'destructive' | 'warning' | 'secondary' {
    if (kind === 'actual' || risk === 'Critical relief') {
        return 'destructive';
    }

    if (
        kind === 'projected' ||
        risk === 'No relief' ||
        risk === 'Relief not ready'
    ) {
        return 'warning';
    }

    return 'secondary';
}

export function ManningReliefRisksCard({
    risks,
    canViewProjected,
}: {
    risks: CrewOperationsManningReliefRisk[];
    canViewProjected: boolean;
}): ReactElement {
    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/2">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight">
                            Manning & Relief Risks
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Actual gaps, projected future gaps, and relief
                            readiness — kept distinct
                        </CardDescription>
                    </div>
                    {canViewProjected ? (
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-8 rounded-lg text-xs"
                            asChild
                        >
                            <Link href={projectedManningRoute.url()}>
                                View Projected Manning
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="space-y-2 pt-4">
                {risks.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground/50">
                        No manning or relief risks to highlight
                    </p>
                ) : (
                    risks.map((item, index) => (
                        <Link
                            key={`${item.kind}-${item.vessel_id}-${item.rank_id}-${item.risk}-${index}`}
                            href={item.href}
                            className="group flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/1 dark:hover:border-white/10"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="truncate text-sm font-semibold text-foreground/80 group-hover:text-primary">
                                        {item.vessel_name}
                                    </p>
                                    <Badge
                                        variant={riskVariant(
                                            item.kind,
                                            item.risk,
                                        )}
                                    >
                                        {item.risk}
                                    </Badge>
                                    <Badge
                                        variant="outline"
                                        className="text-[10px]"
                                    >
                                        {item.kind === 'actual'
                                            ? 'Actual'
                                            : item.kind === 'projected'
                                              ? 'Projected'
                                              : 'Relief'}
                                    </Badge>
                                </div>
                                <p className="mt-0.5 text-xs text-muted-foreground/60">
                                    {item.rank_name}
                                    {item.employee_name
                                        ? ` · ${item.employee_name}`
                                        : ''}
                                </p>
                                <p className="mt-1 text-[11px] text-muted-foreground/50">
                                    When:{' '}
                                    {/^\d{4}-\d{2}-\d{2}/.test(item.when)
                                        ? formatDisplayDate(item.when)
                                        : item.when}
                                </p>
                            </div>
                            <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground/45 opacity-0 transition-all group-hover:opacity-100" />
                        </Link>
                    ))
                )}
            </CardContent>
        </Card>
    );
}
