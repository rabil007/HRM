import { Head, router, useForm } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { VesselTransferRecommendationDialog } from '@/features/organization/crew/actions/vessel-transfer-recommendation-dialog';
import { CrewAssignmentFormFields } from '@/features/organization/crew/components/crew-assignment-form-fields';
import { recommendsVesselTransfer } from '@/features/organization/crew/lib/vessel-transfer-recommendation';
import type {
    CrewAssignmentCreateFormOptions,
    CrewAssignmentFormData,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import {
    index as crewAssignmentsIndex,
    store as storeAssignment,
} from '@/routes/organization/crew-assignments';

export default function CrewAssignmentCreate({
    form_options,
}: {
    form_options: CrewAssignmentCreateFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    const [transferPromptOpen, setTransferPromptOpen] = useState(false);
    const form = useForm<CrewAssignmentFormData>({
        employee_id: null,
        rank_id: null,
        client_id: null,
        vessel_id: null,
        company_visa_type_id: null,
        planned_join_at: '',
        planned_signoff_at: '',
        planned_travel_at: '',
        remarks: '',
    });

    const currentOnVessel = form.data.employee_id
        ? (form_options.active_on_vessel_by_employee?.[
              String(form.data.employee_id)
          ] ?? null)
        : null;
    const destinationVessel = form_options.vessels.find(
        (vessel) => vessel.id === form.data.vessel_id,
    );
    const recommendsTransfer = recommendsVesselTransfer(
        currentOnVessel,
        form.data.vessel_id,
    );

    const handleSubmit = (event: React.FormEvent): void => {
        event.preventDefault();

        if (recommendsTransfer) {
            setTransferPromptOpen(true);

            return;
        }

        form.post(storeAssignment.url());
    };

    return (
        <>
            <Head title="New Crew Assignment" />
            <Main>
                <DetailsHeader
                    kicker="Crew Assignments"
                    title="New Assignment"
                    description="Create a draft mobilisation cycle. Movement actions advance the phase later."
                    backHref={crewAssignmentsIndex.url()}
                    backLabel="Back to Crew Assignments"
                />

                <div className="mb-6 rounded-xl border border-sky-500/35 bg-sky-500/10 p-4">
                    <div className="flex gap-3">
                        <Info
                            className="mt-0.5 size-4 shrink-0 text-sky-700 dark:text-sky-300"
                            aria-hidden
                        />
                        <div className="space-y-1 text-sm text-sky-900 dark:text-sky-100">
                            <p>
                                This creates a P0 Pre-Mobilisation draft. It
                                does not start travel or mark the employee
                                onboard.
                            </p>
                            <p>
                                Use Approve Mobilisation when the mobilisation
                                actually begins.
                            </p>
                        </div>
                    </div>
                </div>

                <Card className="border-border/80 dark:border-white/10">
                    <CardContent className="p-6 md:p-8">
                        <form onSubmit={handleSubmit} className="space-y-8">
                            <CrewAssignmentFormFields
                                form={form}
                                formOptions={form_options}
                            />

                            {currentOnVessel ? (
                                <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-950 dark:text-amber-100">
                                    <p>
                                        {currentOnVessel.employee_name} is
                                        already On Vessel on{' '}
                                        {currentOnVessel.vessel_name ??
                                            'another vessel'}{' '}
                                        ({currentOnVessel.assignment_no}
                                        {currentOnVessel.actual_start_display
                                            ? `, P4 started ${currentOnVessel.actual_start_display}`
                                            : ''}
                                        ).
                                    </p>
                                    <p className="mt-1">
                                        Creating another assignment may produce
                                        conflicting operational history. If this
                                        is a move to a different vessel, use
                                        Transfer Vessel instead.
                                    </p>
                                </div>
                            ) : null}

                            <div className="flex flex-wrap gap-3 border-t border-border/60 pt-6">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="h-11 rounded-xl px-6"
                                >
                                    {form.processing ? (
                                        <Spinner className="mr-2" />
                                    ) : null}
                                    Create Draft Assignment
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11 rounded-xl px-6"
                                    onClick={() =>
                                        router.visit(crewAssignmentsIndex.url())
                                    }
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <VesselTransferRecommendationDialog
                    open={transferPromptOpen}
                    onOpenChange={setTransferPromptOpen}
                    current={currentOnVessel}
                    destinationVesselName={destinationVessel?.name}
                    prefill={{
                        vessel_id: form.data.vessel_id,
                        rank_id: form.data.rank_id,
                        client_id: form.data.client_id,
                        company_visa_type_id: form.data.company_visa_type_id,
                    }}
                />
            </Main>
        </>
    );
}
