import { Head, Link, router, useForm } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import InputError from '@/components/input-error';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { VesselTransferRecommendationDialog } from '@/features/organization/crew/actions/vessel-transfer-recommendation-dialog';
import { CrewAssignmentFormFields } from '@/features/organization/crew/components/crew-assignment-form-fields';
import { recommendsVesselTransfer } from '@/features/organization/crew/lib/vessel-transfer-recommendation';
import type {
    CrewAssignmentCreateFormData,
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import { dashboard } from '@/routes';
import {
    index as crewAssignmentsIndex,
    store as storeAssignment,
} from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

export default function CrewAssignmentCreate({
    form_options,
    can,
}: {
    form_options: CrewAssignmentCreateFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    const [transferPromptOpen, setTransferPromptOpen] = useState(false);
    const form = useForm<CrewAssignmentCreateFormData & { error?: never }>({
        employee_id: null,
        rank_id: null,
        client_id: null,
        vessel_id: null,
        planned_join_at: '',
        current_stage: 'p1',
        submission_intent: can.start ? 'start' : 'draft',
        remarks: '',
    });

    const currentOnVessel = form.data.employee_id
        ? (form_options.active_on_vessel_by_employee?.[
              String(form.data.employee_id)
          ] ?? null)
        : null;

    const currentEmployeeStatus = form.data.employee_id
        ? (form_options.employee_status_by_employee?.[
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
    const canUseRecommendedTransfer =
        recommendsTransfer && currentOnVessel?.can_transfer === true;
    const transferRequiredButUnauthorized =
        recommendsTransfer && currentOnVessel?.can_transfer === false;
    const hasActiveAssignmentConflict =
        (currentEmployeeStatus?.has_active_assignment ?? false) &&
        !canUseRecommendedTransfer;
    const backHref = can.view ? crewAssignmentsIndex.url() : dashboard.url();
    const backLabel = can.view
        ? 'Back to Crew Assignments'
        : 'Back to Dashboard';

    const submit = (intent: 'start' | 'draft'): void => {
        if (canUseRecommendedTransfer) {
            setTransferPromptOpen(true);

            return;
        }

        if (hasActiveAssignmentConflict) {
            return;
        }

        if (intent === 'start' && !can.start) {
            return;
        }

        form.setData('submission_intent', intent);
        form.transform((data) => ({
            ...data,
            submission_intent: intent,
            current_stage: intent === 'start' ? data.current_stage : undefined,
        }));
        form.post(storeAssignment.url(), {
            onFinish: () => form.transform((data) => data),
        });
    };

    const handleSubmit = (event: React.FormEvent): void => {
        event.preventDefault();
        submit(can.start ? 'start' : 'draft');
    };

    return (
        <>
            <Head title="Start Crew Assignment" />
            <Main>
                <DetailsHeader
                    kicker="Crew Assignments"
                    title="Start Crew Assignment"
                    description="Record the crew member's current operational position and start the mobilisation cycle."
                    backHref={backHref}
                    backLabel={backLabel}
                />

                <div className="mx-auto max-w-4xl space-y-6">
                    <div className="rounded-xl border border-sky-500/35 bg-sky-500/10 p-4">
                        <div className="flex gap-3">
                            <Info
                                className="mt-0.5 size-4 shrink-0 text-sky-700 dark:text-sky-300"
                                aria-hidden
                            />
                            <div className="space-y-0.5 text-sm text-sky-900 dark:text-sky-100">
                                <p className="font-medium">
                                    Record the crew member&apos;s current
                                    operational position and start the
                                    mobilisation cycle.
                                </p>
                                <p className="text-xs text-sky-900/80 dark:text-sky-200/80">
                                    Draft remains available when the record is
                                    still incomplete. Future mobilisation
                                    belongs in Crew Planning.
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
                                    mode="create"
                                    showStartFields={can.start}
                                />

                                <div className="flex flex-wrap items-center gap-3 border-t border-border/60 pt-6">
                                    {can.start ? (
                                        <Button
                                            type="submit"
                                            disabled={
                                                form.processing ||
                                                hasActiveAssignmentConflict
                                            }
                                            title={
                                                transferRequiredButUnauthorized
                                                    ? 'Vessel Transfer is required for this move, but you do not have permission to perform it.'
                                                    : hasActiveAssignmentConflict
                                                      ? 'This employee already has an active Crew Assignment. Resolve the conflict above before creating a new one.'
                                                      : undefined
                                            }
                                            className="h-11 rounded-xl px-6"
                                        >
                                            {form.processing &&
                                            form.data.submission_intent ===
                                                'start' ? (
                                                <Spinner className="mr-2" />
                                            ) : null}
                                            Start Assignment
                                        </Button>
                                    ) : null}
                                    <Button
                                        type="button"
                                        variant={
                                            can.start ? 'outline' : 'default'
                                        }
                                        className="h-11 rounded-xl px-6"
                                        disabled={
                                            form.processing ||
                                            hasActiveAssignmentConflict
                                        }
                                        onClick={() => submit('draft')}
                                    >
                                        {form.processing &&
                                        form.data.submission_intent ===
                                            'draft' ? (
                                            <Spinner className="mr-2" />
                                        ) : null}
                                        Save as Draft
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-11 rounded-xl px-6"
                                        onClick={() => router.visit(backHref)}
                                    >
                                        Cancel
                                    </Button>

                                    {!can.start ? (
                                        <p className="w-full text-xs font-medium text-amber-700 dark:text-amber-300">
                                            Starting an operational assignment
                                            requires movement permission. You
                                            can still save a Draft, or ask an
                                            authorized Operations user to start
                                            the assignment.
                                        </p>
                                    ) : null}

                                    {can.view_planning ? (
                                        <p className="w-full text-xs text-muted-foreground">
                                            Planning this for later?{' '}
                                            <Link
                                                href={crewPlanningIndex.url()}
                                                className="font-medium text-primary hover:underline"
                                            >
                                                Plan Crew Instead →
                                            </Link>
                                        </p>
                                    ) : null}

                                    {transferRequiredButUnauthorized ? (
                                        <p className="w-full text-xs font-medium text-amber-700 dark:text-amber-300">
                                            Vessel Transfer is required for this
                                            move. You do not have permission to
                                            perform it; ask an authorized
                                            Operations user to continue.
                                        </p>
                                    ) : hasActiveAssignmentConflict &&
                                      !form.errors.error ? (
                                        <p className="w-full text-xs font-medium text-destructive">
                                            This employee already has an active
                                            Crew Assignment. Resolve the
                                            conflict above before creating a new
                                            one.
                                        </p>
                                    ) : null}

                                    <InputError
                                        message={form.errors.error}
                                        className="w-full"
                                    />
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>

                <VesselTransferRecommendationDialog
                    open={transferPromptOpen}
                    onOpenChange={setTransferPromptOpen}
                    current={currentOnVessel}
                    destinationVesselName={destinationVessel?.name}
                    prefill={{
                        vessel_id: form.data.vessel_id,
                        rank_id: form.data.rank_id,
                        client_id: form.data.client_id,
                    }}
                />
            </Main>
        </>
    );
}
