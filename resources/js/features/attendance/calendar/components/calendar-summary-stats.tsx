import { Link } from '@inertiajs/react';
import { CalendarCheck2, CalendarDays, Clock3, Layers3 } from 'lucide-react';
import { cn } from '@/lib/utils';

function StatCard({
    label,
    value,
    hint,
    icon: Icon,
    accent,
    href,
}: {
    label: string;
    value: string | number;
    hint: string;
    icon: typeof CalendarDays;
    accent: string;
    href?: string;
}) {
    const content = (
        <div className="glass-card group relative overflow-hidden rounded-2xl border border-border/60 bg-card/80 p-5 transition-all duration-300 hover:-translate-y-0.5 hover:border-border hover:shadow-lg dark:border-white/6 dark:bg-white/4 dark:hover:border-white/10">
            <div
                className={cn(
                    'pointer-events-none absolute -right-4 -top-4 size-24 rounded-full opacity-20 blur-2xl transition-opacity group-hover:opacity-30',
                    accent,
                )}
            />
            <div className="relative flex items-start justify-between gap-4">
                <div className="space-y-2">
                    <p className="text-[10px] font-bold uppercase tracking-[0.18em] text-muted-foreground/70">{label}</p>
                    <p className="text-3xl font-extrabold tracking-tight tabular-nums">{value}</p>
                    <p className="text-xs font-medium text-muted-foreground/75">{hint}</p>
                </div>
                <div className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-muted/40 dark:border-white/8 dark:bg-white/6">
                    <Icon className="size-5 text-muted-foreground" />
                </div>
            </div>
        </div>
    );

    if (href) {
        return (
            <Link href={href} className="block">
                {content}
            </Link>
        );
    }

    return content;
}

export function CalendarSummaryStats({
    year,
    requestCount,
    pendingRequestCount,
    leaveDays,
    typeCount,
}: {
    year: number;
    requestCount: number;
    pendingRequestCount: number;
    leaveDays: number;
    typeCount: number;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Approved requests"
                value={requestCount}
                hint={`In ${year}`}
                icon={CalendarCheck2}
                accent="bg-emerald-500"
            />
            <StatCard
                label="Pending requests"
                value={pendingRequestCount}
                hint="Awaiting approval"
                icon={Clock3}
                accent="bg-amber-500"
                href={`/attendance/leave-requests?status=pending`}
            />
            <StatCard
                label="Leave days"
                value={leaveDays}
                hint="Marked on calendar"
                icon={CalendarDays}
                accent="bg-violet-500"
            />
            <StatCard
                label="Leave types"
                value={typeCount}
                hint="Used this year"
                icon={Layers3}
                accent="bg-sky-500"
            />
        </div>
    );
}
