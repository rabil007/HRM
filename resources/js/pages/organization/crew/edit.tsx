import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Info } from 'lucide-react';
import { useEffect } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { CrewAssignmentFormFields } from '@/features/organization/crew/components/crew-assignment-form-fields';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import type {
    CrewAssignmentDetail,
    CrewAssignmentFormData,
    CrewAssignmentFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import {
    show as showAssignment,
    update as updateAssignment,
} from '@/routes/organization/crew-assignments';

export default function CrewAssignmentEdit({
    assignment,
    form_options,
}: {
    assignment: CrewAssignmentDetail;
    form_options: CrewAssignmentFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    const form = useForm<CrewAssignmentFormData>({
        employee_id: assignment.employee?.id ?? null,
        rank_id: assignment.rank?.id ?? null,
        client_id: assignment.client?.id ?? null,
        vessel_id: assignment.vessel?.id ?? null,
        company_visa_type_id: assignment.company_visa_type?.id ?? null,
        planned_join_at: assignment.planned_join_at ?? '',
        planned_signoff_at: assignment.planned_signoff_at ?? '',
        planned_travel_at: assignment.planned_travel_at ?? '',
        remarks: assignment.remarks ?? '',
    });

    useEffect(() => {
        if (!form.isDirty) {
            return;
        }

        const handler = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };

        window.addEventListener('beforeunload', handler);

        return () => window.removeEventListener('beforeunload', handler);
    }, [form.isDirty]);

    const planningFieldsChanged =
        form.data.vessel_id !== (assignment.vessel?.id ?? null) ||
        form.data.rank_id !== (assignment.rank?.id ?? null) ||
        form.data.planned_join_at !== (assignment.planned_join_at ?? '') ||
        form.data.planned_signoff_at !== (assignment.planned_signoff_at ?? '');

    const handleSubmit = (event: React.FormEvent): void => {
        event.preventDefault();
        form.put(updateAssignment.url(assignment.id));
    };

    const employeeLabel = assignment.employee
        ? `${assignment.employee.name}${
              assignment.employee.employee_no
                  ? ` (${assignment.employee.employee_no})`
                  : ''
          }`
        : '—';

    return (
        <>
            <Head title={`Edit ${assignment.assignment_no}`} />
            <Main>
                <DetailsHeader
                    kicker="Current Crew"
                    title={`Edit ${assignment.assignment_no}`}
                    description="Update planning and master-data fields. Phase changes use Movement Actions."
                    backHref={showAssignment.url(assignment.id)}
                    backLabel="Back to Assignment"
                />

                {assignment.current_phase ? (
                    <div className="mb-6 flex flex-wrap items-center gap-3 rounded-xl border border-border/70 bg-muted/20 px-4 py-3">
                        <span className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                            Current phase
                        </span>
                        <CrewPhaseBadge
                            code={assignment.current_phase.code}
                            label={assignment.current_phase.label}
                            status={assignment.current_phase.status}
                        />
                    </div>
                ) : null}

                <div className="mb-6 rounded-xl border border-sky-500/35 bg-sky-500/10 p-4">
                    <div className="flex gap-3">
                        <Info
                            className="mt-0.5 size-4 shrink-0 text-sky-700 dark:text-sky-300"
                            aria-hidden
                        />
                        <div className="space-y-1 text-sm text-sky-900 dark:text-sky-100">
                            <p>
                                You are editing planning and master-data fields
                                only.
                            </p>
                            <p>
                                Phase changes must be recorded through Movement
                                Actions.
                            </p>
                            <p>
                                Eligible changes automatically update the linked
                                Planning bar.
                            </p>
                        </div>
                    </div>
                </div>

                {planningFieldsChanged ? (
                    <div className="mb-6 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
                        <div className="flex gap-3">
                            <AlertTriangle
                                className="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-300"
                                aria-hidden
                            />
                            <div className="space-y-1 text-sm text-amber-900 dark:text-amber-100">
                                <p className="font-medium">
                                    These changes will update the linked
                                    Planning bar
                                </p>
                                <p className="text-amber-800/90 dark:text-amber-200/90">
                                    Vessel, rank, planned join, or planned
                                    sign-off differ from the saved assignment.
                                    Saving will create or update the linked
                                    Planning Gantt bar to match.
                                </p>
                            </div>
                        </div>
                    </div>
                ) : null}

                <Card className="border-border/80 dark:border-white/10">
                    <CardContent className="p-6 md:p-8">
                        <form onSubmit={handleSubmit} className="space-y-8">
                            <CrewAssignmentFormFields
                                form={form}
                                formOptions={form_options}
                                lockEmployee
                                employeeLabel={employeeLabel}
                            />

                            <div className="flex flex-wrap gap-3 border-t border-border/60 pt-6">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="h-11 rounded-xl px-6"
                                >
                                    {form.processing ? (
                                        <Spinner className="mr-2" />
                                    ) : null}
                                    Save Changes
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11 rounded-xl px-6"
                                    onClick={() =>
                                        router.visit(
                                            showAssignment.url(assignment.id),
                                        )
                                    }
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </Main>
        </>
    );
}
