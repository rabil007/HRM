import { Link } from '@inertiajs/react';
import { AlertTriangle, AlertCircle, ChevronRight, Zap } from 'lucide-react';
import type { DashboardAttentionItem } from '../dashboard-types';

type AttentionCenterProps = {
    items?: DashboardAttentionItem[];
};

export function AttentionCenter({ items = [] }: AttentionCenterProps) {
    if (!items || items.length === 0) {
        return null;
    }

    const criticals = items.filter((i) => i.severity === 'critical');
    const warnings = items.filter((i) => i.severity === 'warning');

    return (
        <div className="rounded-xl border border-border/60 bg-card overflow-hidden shadow-sm">
            {/* Header */}
            <div className="flex items-center gap-3 px-4 py-3 border-b border-border/40 bg-muted/20">
                <Zap className="h-4 w-4 text-amber-500 shrink-0" />
                <span className="font-semibold text-sm text-foreground">Action Required</span>
                <div className="flex items-center gap-2 ml-1">
                    {criticals.length > 0 && (
                        <span className="rounded-full bg-rose-500 text-white text-[10px] font-bold px-2 py-0.5 leading-none">
                            {criticals.length} critical
                        </span>
                    )}
                    {warnings.length > 0 && (
                        <span className="rounded-full bg-amber-500 text-white text-[10px] font-bold px-2 py-0.5 leading-none">
                            {warnings.length} warning
                        </span>
                    )}
                </div>
            </div>

            {/* Items list */}
            <div className="divide-y divide-border/30">
                {items.map((item) => {
                    const isCritical = item.severity === 'critical';
                    const Icon = isCritical ? AlertTriangle : AlertCircle;

                    return (
                        <div
                            key={item.key}
                            className="group flex items-center gap-3 px-4 py-3 hover:bg-muted/30 transition-colors"
                        >
                            {/* Severity indicator */}
                            <div
                                className={`h-1.5 w-1.5 rounded-full shrink-0 ${isCritical ? 'bg-rose-500' : 'bg-amber-500'}`}
                            />

                            {/* Icon */}
                            <Icon
                                className={`h-3.5 w-3.5 shrink-0 ${isCritical ? 'text-rose-500' : 'text-amber-500'}`}
                            />

                            {/* Module badge */}
                            <span className="hidden sm:inline-block text-[10px] font-bold uppercase tracking-wider text-muted-foreground bg-muted/60 px-1.5 py-0.5 rounded shrink-0 w-22 text-center">
                                {item.module}
                            </span>

                            {/* Title */}
                            <span className="flex-1 text-sm font-medium text-foreground min-w-0 truncate">
                                {item.title}
                            </span>

                            {/* Description (hidden on small) */}
                            <span className="hidden lg:inline text-xs text-muted-foreground shrink-0 max-w-60 truncate">
                                {item.description}
                            </span>

                            {/* Count pill */}
                            <span
                                className={`shrink-0 text-xs font-bold tabular-nums px-2 py-0.5 rounded-full ${
                                    isCritical
                                        ? 'bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400'
                                        : 'bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400'
                                }`}
                            >
                                {item.count}
                            </span>

                            {/* Action link */}
                            <Link
                                href={item.href}
                                className="shrink-0 flex items-center gap-1 text-xs font-semibold text-primary opacity-70 group-hover:opacity-100 transition-opacity"
                            >
                                {item.action_label}
                                <ChevronRight className="h-3 w-3" />
                            </Link>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
