import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactElement } from 'react';
import { show as vesselManningShow } from '@/actions/App/Http/Controllers/Organization/VesselManningController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { CrewOperationsManningGaps } from '@/features/organization/crew-operations/types';
import { index as vesselManningIndex } from '@/routes/organization/vessel-manning';

export function ManningGapsCard({
    manningGaps,
}: {
    manningGaps: CrewOperationsManningGaps;
}): ReactElement {
    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/2">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight">
                            Manning gaps
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Required vs on-board headcount by vessel and rank
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-2">
                        {manningGaps.understaffed_positions > 0 ? (
                            <Badge
                                variant="destructive"
                                className="tabular-nums"
                            >
                                {manningGaps.understaffed_positions} short
                            </Badge>
                        ) : null}
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-8 rounded-lg text-xs"
                            asChild
                        >
                            <Link href={vesselManningIndex.url()}>
                                View manning
                            </Link>
                        </Button>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-2 pt-4">
                {manningGaps.items.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground/50">
                        All configured positions are fully staffed
                    </p>
                ) : (
                    manningGaps.items.map((item) => (
                        <Link
                            key={`${item.vessel_id}-${item.rank_id}`}
                            href={vesselManningShow.url({
                                vessel: item.vessel_id,
                            })}
                            className="group flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/1 dark:hover:border-white/10"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="truncate text-sm font-semibold text-foreground/80 group-hover:text-primary">
                                        {item.vessel_name}
                                    </p>
                                    <Badge
                                        variant="destructive"
                                        className="tabular-nums"
                                    >
                                        −{item.gap}
                                    </Badge>
                                </div>
                                <p className="mt-0.5 text-xs text-muted-foreground/60">
                                    {item.rank_name}
                                </p>
                                <p className="mt-1 text-[11px] text-muted-foreground/50">
                                    {item.actual_count} on board ·{' '}
                                    {item.required_count} required
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
