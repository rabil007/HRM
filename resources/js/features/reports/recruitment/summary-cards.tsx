import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { RecruitmentReportSummary } from './types';

type SummaryCardsProps = {
    summary: RecruitmentReportSummary;
};

export function RecruitmentReportSummaryCards({ summary }: SummaryCardsProps) {
    const fillRate =
        summary.headcount_total > 0
            ? Math.round(
                  (summary.headcount_confirmed_joined /
                      summary.headcount_total) *
                      100,
              )
            : null;

    return (
        <div className="space-y-4">
            <div>
                <div className="mb-2 flex items-center justify-between">
                    <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        Filtered Candidate Pipeline (In View)
                    </span>
                </div>
                <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-3">
                    <StatCard
                        label="Applications"
                        value={summary.applications_total}
                        hint="In active view"
                    />
                    <StatCard
                        label="Selected"
                        value={summary.selected_count}
                        hint="Outcome: Selected"
                    />
                    <StatCard
                        label="Joining"
                        value={summary.joining_count}
                        hint="Joining stage"
                    />
                    <StatCard
                        label="Joined"
                        value={summary.joined_count}
                        hint="Joined in view"
                        highlight={summary.joined_count > 0}
                    />
                    <StatCard
                        label="Rejected"
                        value={summary.rejected_count}
                        hint="Not progressed"
                    />
                    <StatCard
                        label="Converted"
                        value={summary.converted_count}
                        hint="Employee linked"
                        highlight={summary.converted_count > 0}
                    />
                </div>
            </div>

            {summary.headcount_total > 0 ? (
                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            Position Fulfillment (Scoped Requirements)
                        </span>
                    </div>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-3">
                        <StatCard
                            label="Headcount"
                            value={summary.headcount_total}
                            hint="Required slots"
                        />
                        <StatCard
                            label="Confirmed Joined"
                            value={summary.headcount_confirmed_joined}
                            hint="All joined candidates"
                            highlight={summary.headcount_confirmed_joined > 0}
                        />
                        <StatCard
                            label="Remaining"
                            value={summary.headcount_remaining}
                            hint={
                                fillRate !== null
                                    ? `${fillRate}% filled`
                                    : 'Shortage slots'
                            }
                            warn={summary.headcount_remaining > 0}
                        />
                        {summary.headcount_overfill > 0 ? (
                            <StatCard
                                label="Overfill"
                                value={summary.headcount_overfill}
                                hint="Above line target"
                                warn
                            />
                        ) : null}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function StatCard({
    label,
    value,
    hint,
    highlight = false,
    warn = false,
}: {
    label: string;
    value: number;
    hint: string;
    highlight?: boolean;
    warn?: boolean;
}) {
    return (
        <Card
            className={cn(
                'transition-colors',
                highlight && 'border-emerald-500/30 bg-emerald-500/5',
                warn && 'border-amber-500/30 bg-amber-500/5',
            )}
        >
            <CardContent className="p-3">
                <p className="text-sm font-semibold tracking-tight">{label}</p>
                <p className="mt-1 text-xl font-bold tabular-nums">
                    {value.toLocaleString()}
                </p>
                <p className="mt-1 text-[11px] text-muted-foreground">{hint}</p>
            </CardContent>
        </Card>
    );
}
