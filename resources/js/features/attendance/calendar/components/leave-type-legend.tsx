import { Sparkles } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { CalendarLeaveType } from '../types';

const FALLBACK_COLOR = '#8b5cf6';

export function LeaveTypeLegend({ leaveTypes, year }: { leaveTypes: CalendarLeaveType[]; year: number }) {
    return (
        <Card className="glass-card overflow-hidden border-border/60 bg-card/80 dark:border-white/6 dark:bg-white/4">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/6">
                <div className="flex items-center gap-2">
                    <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Sparkles className="size-4" />
                    </div>
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight">Legend</CardTitle>
                        <p className="text-xs font-medium text-muted-foreground/75">Approved leave in {year}</p>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-2 pt-4">
                <div className="flex items-center gap-3 rounded-xl border border-border/60 bg-muted/30 px-3 py-2.5 dark:border-white/6 dark:bg-white/4">
                    <span
                        className="size-3.5 shrink-0 rounded-full ring-2 ring-white/20"
                        style={{ backgroundColor: FALLBACK_COLOR }}
                    />
                    <span className="text-sm font-semibold">Approved leave day</span>
                </div>
                {leaveTypes.map((leaveType) => (
                    <div
                        key={leaveType.id}
                        className="flex items-center gap-3 rounded-xl border border-border/60 bg-muted/20 px-3 py-2.5 transition-colors hover:bg-muted/40 dark:border-white/6 dark:bg-white/3 dark:hover:bg-white/6"
                    >
                        <span
                            className="size-3.5 shrink-0 rounded-full ring-2 ring-white/20"
                            style={{ backgroundColor: leaveType.color ?? FALLBACK_COLOR }}
                        />
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-semibold">{leaveType.name}</div>
                        </div>
                        <Badge
                            variant="secondary"
                            className="shrink-0 bg-muted/50 text-[10px] font-bold uppercase tracking-wider dark:bg-white/8"
                        >
                            {leaveType.code}
                        </Badge>
                    </div>
                ))}
                {leaveTypes.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border/60 px-3 py-6 text-center text-sm text-muted-foreground/80 dark:border-white/8">
                        No approved leave types in this year.
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}
