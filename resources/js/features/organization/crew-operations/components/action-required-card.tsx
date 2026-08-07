import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
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

const SEVERITY_BADGE: Record<string, 'destructive' | 'warning' | 'secondary'> =
    {
        critical: 'destructive',
        warning: 'warning',
        info: 'secondary',
    };

export function ActionRequiredCard({
    items,
}: {
    items: CrewOperationsActionItem[];
}): ReactElement {
    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/2">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                <CardTitle className="text-base font-bold tracking-tight">
                    Action Required
                </CardTitle>
                <CardDescription className="text-xs">
                    Highest-priority operational issues — max 10
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-2 pt-4">
                {items.length === 0 ? (
                    <p className="py-10 text-center text-sm text-muted-foreground/50">
                        Nothing urgent right now
                    </p>
                ) : (
                    items.map((item, index) => (
                        <Link
                            key={`${item.type}-${item.href}-${index}`}
                            href={item.href}
                            className="group flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/1 dark:hover:border-white/10"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="truncate text-sm font-semibold text-foreground/80 group-hover:text-primary">
                                        {item.title}
                                    </p>
                                    <Badge
                                        variant={
                                            SEVERITY_BADGE[item.severity] ??
                                            'secondary'
                                        }
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
                                    {item.meta
                                        ? ` · ${formatDisplayDate(item.meta)}`
                                        : ''}
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
