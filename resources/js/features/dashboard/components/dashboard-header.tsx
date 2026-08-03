import { Building2, Clock, RefreshCw, Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { PersonalSummary } from '../dashboard-types';

type DashboardHeaderProps = {
    personalSummary: PersonalSummary;
    lastUpdated: Date;
    isRefreshing: boolean;
    onRefresh: () => void;
};

export function DashboardHeader({
    personalSummary,
    lastUpdated,
    isRefreshing,
    onRefresh,
}: DashboardHeaderProps) {
    const formattedTime = lastUpdated.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });

    return (
        <section className="relative overflow-hidden rounded-3xl border border-primary/15 bg-linear-to-br from-primary/10 via-card to-card px-5 py-6 shadow-sm sm:px-7 sm:py-7 dark:from-primary/15 dark:via-card/90 dark:to-card/70">
            <div className="pointer-events-none absolute -top-24 -right-20 h-64 w-64 rounded-full bg-primary/10 blur-3xl" />
            <div className="pointer-events-none absolute -bottom-32 left-1/3 h-56 w-56 rounded-full bg-sky-500/8 blur-3xl" />

            <div className="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div className="max-w-3xl space-y-3">
                    <div className="inline-flex items-center gap-1.5 rounded-full border border-primary/15 bg-background/60 px-2.5 py-1 text-[11px] font-semibold tracking-wide text-primary backdrop-blur-sm">
                        <Sparkles className="h-3 w-3" />
                        Today&apos;s workspace
                    </div>

                    <div className="space-y-1.5">
                        <h1 className="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl lg:text-4xl">
                            Welcome back, {personalSummary.user_name || 'User'}
                        </h1>
                        <p className="max-w-2xl text-sm leading-6 text-muted-foreground sm:text-base">
                            Here&apos;s what is happening across your
                            organization today.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-muted-foreground">
                        {personalSummary.company_name && (
                            <>
                                <span className="inline-flex items-center gap-1.5 font-medium text-foreground">
                                    <Building2 className="h-4 w-4 text-primary" />
                                    {personalSummary.company_name}
                                </span>
                                <span
                                    className="h-1 w-1 rounded-full bg-border"
                                    aria-hidden="true"
                                />
                            </>
                        )}
                        <span>{personalSummary.today}</span>
                    </div>
                </div>

                <div className="flex flex-col gap-2 sm:flex-row sm:items-center lg:flex-col lg:items-stretch xl:flex-row xl:items-center">
                    <div className="flex items-center gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 text-xs text-muted-foreground shadow-xs backdrop-blur-sm">
                        <span
                            className="relative flex h-2 w-2"
                            aria-hidden="true"
                        >
                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                            <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                        </span>
                        <span>Auto-updated</span>
                        <span className="text-border">•</span>
                        <span className="flex items-center gap-1 font-mono text-[11px]">
                            <Clock className="h-3 w-3" />
                            {formattedTime}
                        </span>
                    </div>

                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onRefresh}
                        disabled={isRefreshing}
                        className="h-9 gap-1.5 rounded-xl border-border/70 bg-background/70 px-3 text-xs shadow-xs backdrop-blur-sm hover:bg-background"
                    >
                        <RefreshCw
                            className={`h-3.5 w-3.5 ${isRefreshing ? 'animate-spin' : ''}`}
                        />
                        {isRefreshing ? 'Refreshing' : 'Refresh data'}
                    </Button>
                </div>
            </div>
        </section>
    );
}
