import { Activity, Clock } from 'lucide-react';
import { activityLogs } from '@/routes/organization';
import { DashboardSection } from '../components/dashboard-section';
import type { AuditDashboardSummary } from '../dashboard-types';

type ActivitySectionProps = {
    summary?: AuditDashboardSummary;
};

export function ActivitySection({ summary }: ActivitySectionProps) {
    if (!summary || !summary.recent || summary.recent.length === 0) {
        return null;
    }

    return (
        <DashboardSection
            title="Recent Activity Log"
            description="System and audit events across company modules"
            icon={Activity}
            actionLabel="View Audit Trail"
            actionHref={activityLogs.url()}
        >
            <div className="rounded-xl bg-card border border-border/50 shadow-sm divide-y divide-border/40">
                {summary.recent.map((item) => (
                    <div key={item.id} className="p-3.5 flex items-center justify-between gap-3 text-xs hover:bg-muted/20 transition-colors">
                        <div className="space-y-0.5 min-w-0">
                            <div className="flex items-center gap-2">
                                <span className="font-semibold text-foreground">{item.causer_name}</span>
                                <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-mono text-muted-foreground">
                                    {item.subject_type}
                                </span>
                            </div>
                            <p className="text-muted-foreground truncate">{item.description}</p>
                        </div>

                        <div className="flex items-center gap-1 text-[11px] text-muted-foreground shrink-0 font-mono">
                            <Clock className="h-3 w-3" />
                            {item.created_at ? new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}
                        </div>
                    </div>
                ))}
            </div>
        </DashboardSection>
    );
}
