import { Head, router, useForm } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useEffect } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { CrewAssignmentCommonFields } from '@/features/organization/crew/components/crew-assignment-common-fields';
import { CrewAssignmentEditContextPanel } from '@/features/organization/crew/components/crew-assignment-edit-context-panel';
import { CrewMemberFields } from '@/features/organization/crew/components/crew-member-fields';
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
    can,
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
        planned_join_at: assignment.planned_join_at ?? '',
        planned_arrival_at: assignment.planned_arrival_at ?? '',
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
                    kicker="Crew Assignments"
                    title={`Edit ${assignment.assignment_no}`}
                    description="Update planning details on this mobilisation. Actual movements remain in Movement Actions."
                    backHref={showAssignment.url(assignment.id)}
                    backLabel="Back to Assignment"
                />

                <div className="mb-4 rounded-xl border border-sky-500/35 bg-sky-500/10 p-3.5">
                    <div className="flex gap-2.5">
                        <Info
                            className="mt-0.5 size-4 shrink-0 text-sky-700 dark:text-sky-300"
                            aria-hidden
                        />
                        <p className="text-sm text-sky-900 dark:text-sky-100">
                            Update assignment planning here. Actual operational
                            events belong in Movement Actions on the assignment.
                        </p>
                    </div>
                </div>

                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_19rem] lg:gap-5 xl:grid-cols-[minmax(0,1fr)_21rem] 2xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <Card className="border-border/80 dark:border-white/10">
                        <CardContent className="p-6 md:p-8">
                            <form onSubmit={handleSubmit} className="space-y-8">
                                <section className="space-y-4">
                                    <div>
                                        <h2 className="text-sm font-semibold tracking-tight">
                                            Crew Members
                                        </h2>
                                        <p className="text-xs text-muted-foreground">
                                            Employee is locked after assignment
                                            creation. Rank and Arrival Date
                                            remain editable.
                                        </p>
                                    </div>

                                    <CrewMemberFields
                                        data={{
                                            employee_id: form.data.employee_id,
                                            rank_id: form.data.rank_id,
                                            planned_arrival_at:
                                                form.data.planned_arrival_at,
                                        }}
                                        onChange={(memberData) => {
                                            form.setData({
                                                ...form.data,
                                                rank_id: memberData.rank_id,
                                                planned_arrival_at:
                                                    memberData.planned_arrival_at,
                                            });
                                        }}
                                        formOptions={form_options}
                                        errors={form.errors}
                                        lockEmployee
                                        employeeLabel={employeeLabel}
                                        currentPhase={assignment.current_phase}
                                    />
                                </section>

                                <CrewAssignmentCommonFields
                                    form={form}
                                    formOptions={form_options}
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
                                                showAssignment.url(
                                                    assignment.id,
                                                ),
                                            )
                                        }
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    <CrewAssignmentEditContextPanel
                        className="lg:sticky lg:top-4"
                        assignment={assignment}
                        formData={form.data}
                        formOptions={form_options}
                        permissions={can}
                    />
                </div>
            </Main>
        </>
    );
}
