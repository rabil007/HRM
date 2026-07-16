import { Head, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import type {
    CrewAssignmentDetail,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';

export default function CrewAssignmentShow({
    assignment,
    recent_activity,
    can,
}: {
    assignment: CrewAssignmentDetail;
    recent_activity: RecentActivityItem[];
    can: CrewAssignmentPagePermissions;
}) {
    return (
        <>
            <Head title={`Assignment ${assignment.assignment_no}`} />
            <Main>
                <div className="mb-6">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.visit('/organization/crew')}
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to Crew Assignments
                    </Button>
                </div>

                <PageHeader
                    title={assignment.assignment_no}
                    description={`Status: ${assignment.status_label}`}
                    right={
                        can.update ? (
                            <Button
                                onClick={() =>
                                    router.visit(`/organization/crew/${assignment.id}/edit`)
                                }
                            >
                                Edit
                            </Button>
                        ) : undefined
                    }
                />

                <div className="space-y-6">
                    <div className="glass-card rounded-xl p-6">
                        <h3 className="mb-4 font-semibold">Details</h3>
                        <dl className="grid grid-cols-2 gap-4">
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Employee
                                </dt>
                                <dd className="mt-1">
                                    {assignment.employee?.name ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Vessel
                                </dt>
                                <dd className="mt-1">
                                    {assignment.vessel?.name ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Rank
                                </dt>
                                <dd className="mt-1">
                                    {assignment.rank?.name ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Client
                                </dt>
                                <dd className="mt-1">
                                    {assignment.client?.name ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Current Phase
                                </dt>
                                <dd className="mt-1">
                                    {assignment.current_phase ? (
                                        <Badge variant="outline">
                                            {assignment.current_phase.label}
                                        </Badge>
                                    ) : (
                                        'N/A'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Days in Phase
                                </dt>
                                <dd className="mt-1">
                                    {assignment.days_in_phase ?? 'N/A'}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div className="glass-card rounded-xl p-6">
                        <h3 className="mb-4 font-semibold">Phase Timeline</h3>
                        <div className="space-y-3">
                            {assignment.phase_timeline.map((phase) => (
                                <div
                                    key={phase.id}
                                    className="flex items-center gap-4 rounded-lg border p-3"
                                >
                                    <Badge variant="outline">
                                        {phase.phase_label}
                                    </Badge>
                                    <div className="flex-1 text-sm">
                                        <div className="font-medium">
                                            {phase.status_label}
                                        </div>
                                        {phase.actual_start_at && (
                                            <div className="text-muted-foreground">
                                                {phase.actual_start_at}
                                                {phase.actual_end_at &&
                                                    ` - ${phase.actual_end_at}`}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {assignment.warnings.length > 0 && (
                        <div className="glass-card rounded-xl border-amber-500 p-6">
                            <h3 className="mb-4 font-semibold text-amber-600">
                                Warnings
                            </h3>
                            <div className="space-y-2">
                                {assignment.warnings.map((warning, idx) => (
                                    <div
                                        key={idx}
                                        className="rounded-lg border border-amber-500/20 bg-amber-50 p-3 dark:bg-amber-950/20"
                                    >
                                        <div className="font-medium text-amber-900 dark:text-amber-100">
                                            {warning.label}
                                        </div>
                                        <div className="text-sm text-amber-700 dark:text-amber-300">
                                            {warning.message}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {can.view_audit && recent_activity.length > 0 && (
                        <RecentActivityCard
                            items={recent_activity}
                            description="Latest changes for this crew assignment."
                        />
                    )}
                </div>
            </Main>
        </>
    );
}
