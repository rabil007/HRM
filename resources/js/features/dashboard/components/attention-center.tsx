import { Link } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, ChevronRight, Zap } from 'lucide-react';
import type { DashboardAttentionItem } from '../dashboard-types';

type AttentionCenterProps = {
    items?: DashboardAttentionItem[];
};

export function AttentionCenter({ items = [] }: AttentionCenterProps) {
    if (!items || items.length === 0) {
        return null;
    }

    const criticalCount = items.filter(
        (item) => item.severity === 'critical',
    ).length;
    const warningCount = items.filter(
        (item) => item.severity === 'warning',
    ).length;

    const severityStyles = {
        critical: {
            dot: 'bg-rose-500',
            icon: 'text-rose-600 dark:text-rose-400',
            surface: 'bg-rose-500/10',
            count: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
        },
        warning: {
            dot: 'bg-amber-500',
            icon: 'text-amber-600 dark:text-amber-400',
            surface: 'bg-amber-500/10',
            count: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        },
        info: {
            dot: 'bg-sky-500',
            icon: 'text-sky-600 dark:text-sky-400',
            surface: 'bg-sky-500/10',
            count: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
        },
    };

    return (
        <section
            aria-labelledby="attention-heading"
            className="overflow-hidden rounded-2xl border border-amber-500/20 bg-card/80 shadow-sm"
        >
            <div className="flex flex-col gap-3 border-b border-border/50 bg-amber-500/5 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400">
                        <Zap className="h-4 w-4" />
                    </div>
                    <div>
                        <h2
                            id="attention-heading"
                            className="text-sm font-semibold text-foreground"
                        >
                            Needs your attention
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            {items.length}{' '}
                            {items.length === 1 ? 'item' : 'items'} waiting for
                            review
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    {criticalCount > 0 && (
                        <span className="rounded-full border border-rose-500/15 bg-rose-500/10 px-2.5 py-1 text-[10px] font-semibold text-rose-700 dark:text-rose-300">
                            {criticalCount} critical
                        </span>
                    )}
                    {warningCount > 0 && (
                        <span className="rounded-full border border-amber-500/15 bg-amber-500/10 px-2.5 py-1 text-[10px] font-semibold text-amber-700 dark:text-amber-300">
                            {warningCount} warning
                        </span>
                    )}
                </div>
            </div>

            <div className="divide-y divide-border/40">
                {items.map((item) => {
                    const isCritical = item.severity === 'critical';
                    const Icon = isCritical ? AlertTriangle : AlertCircle;
                    const styles = severityStyles[item.severity];

                    return (
                        <Link
                            key={item.key}
                            href={item.href}
                            className="group flex items-center gap-3 px-4 py-3.5 transition-colors hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset sm:px-5"
                        >
                            <div
                                className={`relative flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${styles.surface}`}
                            >
                                <Icon className={`h-4 w-4 ${styles.icon}`} />
                                <span
                                    className={`absolute -top-0.5 -right-0.5 h-2 w-2 rounded-full ring-2 ring-card ${styles.dot}`}
                                />
                            </div>

                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="truncate text-sm font-medium text-foreground">
                                        {item.title}
                                    </span>
                                    <span className="hidden shrink-0 rounded-md bg-muted/60 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase sm:inline-block">
                                        {item.module}
                                    </span>
                                </div>
                                {item.description && (
                                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                        {item.description}
                                    </p>
                                )}
                            </div>

                            <span
                                className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold tabular-nums ${styles.count}`}
                            >
                                {item.count}
                            </span>

                            <span className="hidden shrink-0 items-center gap-1 text-xs font-semibold text-primary sm:flex">
                                {item.action_label}
                                <ChevronRight className="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" />
                            </span>
                        </Link>
                    );
                })}
            </div>
        </section>
    );
}
