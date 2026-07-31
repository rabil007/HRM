import { RefreshCw, Clock, Building2 } from 'lucide-react';
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
        second: '2-digit',
    });

    return (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between rounded-2xl bg-card p-6 shadow-sm border border-border/50">
            <div className="space-y-1">
                <div className="flex items-center gap-2">
                    <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                        Welcome back, {personalSummary.user_name || 'User'}
                    </h1>
                </div>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                    {personalSummary.company_name && (
                        <span className="flex items-center gap-1.5 font-medium text-foreground">
                            <Building2 className="h-4 w-4 text-primary" />
                            {personalSummary.company_name}
                        </span>
                    )}
                    <span>{personalSummary.today}</span>
                </div>
            </div>

            <div className="flex items-center gap-3">
                <div className="flex items-center gap-2 rounded-lg bg-muted/50 px-3 py-1.5 text-xs text-muted-foreground">
                    <span className="relative flex h-2 w-2">
                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    <span>Updated automatically</span>
                    <span className="text-muted-foreground/60">•</span>
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
                    className="h-9 gap-1.5 text-xs"
                >
                    <RefreshCw className={`h-3.5 w-3.5 ${isRefreshing ? 'animate-spin' : ''}`} />
                    Refresh
                </Button>
            </div>
        </div>
    );
}
