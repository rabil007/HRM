import { Link } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    ArrowUpRight,
    Clock,
    Home,
    ShieldAlert,
    UserCheck,
    UserX,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { CrewOperationsActionItem } from '@/features/organization/crew-operations/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

const SEVERITY_BADGE: Record<string, 'destructive' | 'warning' | 'secondary'> =
    {
        critical: 'destructive',
        warning: 'warning',
        info: 'secondary',
    };

const TYPE_ICON: Record<string, LucideIcon> = {
    current_manning_gap: AlertOctagon,
    signoff_overdue: Clock,
    signoff_due_today_no_relief: UserX,
    critical_relief_risk: ShieldAlert,
    imminent_signoff_relief_not_ready: UserCheck,
    projected_future_gap: AlertTriangle,
    overdue_movement_correction: Clock,
    needs_update: AlertTriangle,
    overdue_home: Home,
};

function ActionRequiredRowContent({
    item,
}: {
    item: CrewOperationsActionItem;
}): ReactElement {
    const Icon = TYPE_ICON[item.type] ?? AlertTriangle;

    return (
        <>
            <div
                className={cn(
                    'flex size-8 shrink-0 items-center justify-center rounded-lg',
                    item.severity === 'critical' &&
                        'bg-destructive/10 text-destructive',
                    item.severity === 'warning' && 'bg-warning/10 text-warning',
                    item.severity === 'info' &&
                        'bg-muted/40 text-muted-foreground',
                )}
            >
                <Icon className="size-3.5" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="truncate text-sm font-semibold text-foreground/80 group-hover:text-primary">
                        {item.title}
                    </p>
                    <Badge
                        variant={SEVERITY_BADGE[item.severity] ?? 'secondary'}
                    >
                        {item.severity}
                    </Badge>
                </div>
                {item.subtitle ? (
                    <p className="mt-0.5 truncate text-xs text-muted-foreground/60">
                        {item.subtitle}
                    </p>
                ) : null}
                <p className="mt-1 text-[11px] text-muted-foreground/55">
                    {item.problem}
                    {item.meta ? ` · ${formatDisplayDate(item.meta)}` : ''}
                </p>
            </div>
            {item.href ? (
                <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground/45 opacity-0 transition-all group-hover:opacity-100" />
            ) : null}
        </>
    );
}

export function ActionRequiredCard({
    items,
}: {
    items: CrewOperationsActionItem[];
}): ReactElement {
    const criticalItems = items.filter((i) => i.severity === 'critical');
    const warningItems = items.filter((i) => i.severity !== 'critical');

    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/2">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight">
                            Action Required
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Highest-priority operational issues
                        </CardDescription>
                    </div>
                    {items.length > 0 ? (
                        <span className="inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-destructive/15 px-1.5 text-xs font-bold text-destructive tabular-nums">
                            {items.length}
                        </span>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="space-y-2 pt-4">
                {items.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 py-10">
                        <div className="flex size-10 items-center justify-center rounded-full bg-emerald-500/10">
                            <UserCheck className="size-5 text-emerald-500" />
                        </div>
                        <p className="text-center text-sm font-medium text-muted-foreground/60">
                            Nothing urgent right now
                        </p>
                    </div>
                ) : (
                    <>
                        {criticalItems.length > 0 ? (
                            <div className="space-y-1.5">
                                {criticalItems.map((item, index) => {
                                    const rowClassName = cn(
                                        'flex items-center gap-3 rounded-xl border border-l-2 border-destructive/20 border-l-destructive/60 bg-destructive/3 p-3 dark:border-destructive/15 dark:bg-destructive/5',
                                        item.href
                                            ? 'group cursor-pointer transition-all hover:bg-destructive/8 dark:hover:bg-destructive/10'
                                            : null,
                                    );

                                    if (item.href) {
                                        return (
                                            <Link
                                                key={`${item.type}-${item.href}-${index}`}
                                                href={item.href}
                                                className={rowClassName}
                                            >
                                                <ActionRequiredRowContent
                                                    item={item}
                                                />
                                            </Link>
                                        );
                                    }

                                    return (
                                        <div
                                            key={`${item.type}-${index}`}
                                            className={rowClassName}
                                        >
                                            <ActionRequiredRowContent
                                                item={item}
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                        ) : null}

                        {warningItems.length > 0 ? (
                            <div className="space-y-1.5">
                                {criticalItems.length > 0 ? (
                                    <p className="px-1 pt-1 text-[10px] font-bold tracking-[0.15em] text-muted-foreground/50 uppercase">
                                        Warnings
                                    </p>
                                ) : null}
                                {warningItems.map((item, index) => {
                                    const rowClassName = cn(
                                        'flex items-center gap-3 rounded-xl border border-l-2 border-warning/20 border-l-warning/60 bg-warning/3 p-3 dark:border-warning/15 dark:bg-warning/5',
                                        item.href
                                            ? 'group cursor-pointer transition-all hover:bg-warning/8 dark:hover:bg-warning/10'
                                            : null,
                                    );

                                    if (item.href) {
                                        return (
                                            <Link
                                                key={`${item.type}-${item.href}-${index}`}
                                                href={item.href}
                                                className={rowClassName}
                                            >
                                                <ActionRequiredRowContent
                                                    item={item}
                                                />
                                            </Link>
                                        );
                                    }

                                    return (
                                        <div
                                            key={`${item.type}-${index}`}
                                            className={rowClassName}
                                        >
                                            <ActionRequiredRowContent
                                                item={item}
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                        ) : null}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
