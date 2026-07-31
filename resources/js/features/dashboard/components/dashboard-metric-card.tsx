import { Link } from '@inertiajs/react';
import type { LucideIcon} from 'lucide-react';
import { ArrowUpRight } from 'lucide-react';

type DashboardMetricCardProps = {
    title: string;
    value: string | number;
    subtitle?: string;
    icon: LucideIcon;
    iconColor?: string;
    badgeText?: string;
    badgeVariant?: 'default' | 'success' | 'warning' | 'danger' | 'info';
    href?: string;
};

export function DashboardMetricCard({
    title,
    value,
    subtitle,
    icon: Icon,
    iconColor = 'text-primary',
    badgeText,
    badgeVariant = 'default',
    href,
}: DashboardMetricCardProps) {
    const badgeStyles = {
        default: 'bg-muted text-muted-foreground',
        success: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        warning: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        danger: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
        info: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    };

    const cardContent = (
        <div className="group relative flex flex-col justify-between rounded-xl bg-card p-5 border border-border/50 shadow-sm transition-all duration-200 hover:shadow-md hover:border-border">
            <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                    {title}
                </span>
                <div className={`rounded-lg bg-muted/60 p-2.5 ${iconColor}`}>
                    <Icon className="h-5 w-5" />
                </div>
            </div>

            <div className="mt-3 space-y-1">
                <div className="flex items-baseline gap-2">
                    <span className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                        {value}
                    </span>
                    {badgeText && (
                        <span
                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${badgeStyles[badgeVariant]}`}
                        >
                            {badgeText}
                        </span>
                    )}
                </div>
                {subtitle && (
                    <p className="text-xs text-muted-foreground">
                        {subtitle}
                    </p>
                )}
            </div>

            {href && (
                <div className="mt-3 pt-2 border-t border-border/40 flex items-center justify-between text-xs font-medium text-primary group-hover:underline">
                    <span>View details</span>
                    <ArrowUpRight className="h-3.5 w-3.5 opacity-70 group-hover:opacity-100 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition-transform" />
                </div>
            )}
        </div>
    );

    if (href) {
        return <Link href={href}>{cardContent}</Link>;
    }

    return cardContent;
}
