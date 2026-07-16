import { Head, router, useForm } from '@inertiajs/react';
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
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <span>
                                Update planning fields only. Phase changes use
                                movement actions.
                            </span>
                            {assignment.current_phase ? (
                                <CrewPhaseBadge
                                    code={assignment.current_phase.code}
                                    label={assignment.current_phase.label}
                                    status={assignment.current_phase.status}
                                />
                            ) : null}
                        </span>
                    }
                    backHref={showAssignment.url(assignment.id)}
                    backLabel="Back to Assignment"
                />

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
