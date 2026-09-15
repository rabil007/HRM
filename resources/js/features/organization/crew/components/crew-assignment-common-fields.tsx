import type { ReactElement } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    applyClientChange,
    applyVesselChange,
    filterVesselsForClient,
    selectedVesselIsLegacyUnassigned,
} from '@/features/organization/crew/lib/crew-assignment-client-vessel';
import { crewPhaseDescription } from '@/features/organization/crew/lib/crew-phase-descriptions';
import type {
    CrewAssignmentCreateFormOptions,
    CrewAssignmentStartStage,
} from '@/features/organization/crew/types';
import { CREW_DIRECT_START_STAGES } from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

export type CrewAssignmentCommonFieldsData = {
    client_id: number | null;
    vessel_id: number | null;
    planned_join_at: string;
    current_stage: CrewAssignmentStartStage;
    remarks: string;
};

type CommonFieldsForm = {
    data: CrewAssignmentCommonFieldsData;
    setData: {
        <K extends keyof CrewAssignmentCommonFieldsData>(
            key: K,
            value: CrewAssignmentCommonFieldsData[K],
        ): void;
        (data: CrewAssignmentCommonFieldsData): void;
    };
    errors: Record<string, string | undefined>;
};

const STAGE_KIND: Record<CrewAssignmentStartStage, string> = {
    p1: 'Default.',
    p0: 'Optional.',
};

export function CrewAssignmentCommonFields({
    form,
    formOptions,
    showStartFields = true,
    stagePresentation = 'select',
    showMasterFields = true,
}: {
    form: CommonFieldsForm;
    formOptions: CrewAssignmentCreateFormOptions;
    showStartFields?: boolean;
    stagePresentation?: 'select' | 'cards';
    showMasterFields?: boolean;
}): ReactElement {
    const vesselsForClient = filterVesselsForClient(
        formOptions.vessels,
        form.data.client_id,
        form.data.vessel_id,
    );
    const legacyUnassigned = selectedVesselIsLegacyUnassigned(
        formOptions.vessels,
        form.data.vessel_id,
    );

    const setClientId = (value: string): void => {
        form.setData({
            ...form.data,
            ...applyClientChange(form.data, formOptions, value),
        });
    };

    const setVesselId = (value: string): void => {
        form.setData({
            ...form.data,
            ...applyVesselChange(form.data, formOptions, value),
        });
    };

    return (
        <section className="space-y-6">
            {showMasterFields ? (
                <div>
                    <h2 className="text-sm font-semibold tracking-tight">
                        Assignment Details
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Expected Vessel Join is a target only. Actual vessel
                        joining is recorded later through Join Vessel.
                    </p>
                </div>
            ) : (
                <div>
                    <h2 className="text-sm font-semibold tracking-tight">
                        Operational Start
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Choose the initial movement stage and optional remarks
                        for this mobilisation.
                    </p>
                </div>
            )}

            <div className="grid gap-4 md:grid-cols-2">
                {showMasterFields ? (
                    <>
                        <div className="space-y-2">
                            <Label htmlFor="crew-client">
                                Client{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <AppSelect
                                value={form.data.client_id?.toString() ?? ''}
                                onValueChange={setClientId}
                                variant="dark"
                                placeholder="Select client..."
                                searchPlaceholder="Search client..."
                            >
                                <AppSelectItem value="">
                                    No client
                                </AppSelectItem>
                                {formOptions.clients.map((client) => (
                                    <AppSelectItem
                                        key={client.id}
                                        value={String(client.id)}
                                    >
                                        {client.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <InputError message={form.errors.client_id} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="crew-vessel">
                                Vessel{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional until vessel joining)
                                </span>
                            </Label>
                            <AppSelect
                                value={form.data.vessel_id?.toString() ?? ''}
                                onValueChange={setVesselId}
                                variant="dark"
                                placeholder="Select vessel..."
                                searchPlaceholder="Search vessel..."
                            >
                                <AppSelectItem value="">
                                    No vessel
                                </AppSelectItem>
                                {vesselsForClient.map((vessel) => (
                                    <AppSelectItem
                                        key={vessel.id}
                                        value={String(vessel.id)}
                                    >
                                        {vessel.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.data.client_id !== null &&
                            vesselsForClient.length === 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    No vessels are assigned to this client.
                                </p>
                            ) : null}
                            {legacyUnassigned ? (
                                <p className="text-xs text-muted-foreground">
                                    This vessel has no current client
                                    assignment. Map the vessel before changing
                                    the assignment&apos;s Client or Vessel.
                                </p>
                            ) : null}
                            <InputError message={form.errors.vessel_id} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="planned_join_at">
                                Expected Vessel Join{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="planned_join_at"
                                type="date"
                                className="h-11"
                                value={form.data.planned_join_at}
                                onChange={(event) =>
                                    form.setData(
                                        'planned_join_at',
                                        event.target.value,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Expected date the crew member should join the
                                vessel. Actual joining is recorded later through
                                Join Vessel.
                            </p>
                            <InputError message={form.errors.planned_join_at} />
                        </div>
                    </>
                ) : null}

                {showStartFields ? (
                    stagePresentation === 'cards' ? (
                        <fieldset className="space-y-3 md:col-span-2">
                            <legend className="text-sm font-medium">
                                Initial Assignment Stage
                            </legend>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {CREW_DIRECT_START_STAGES.map((stage) => {
                                    const selected =
                                        form.data.current_stage === stage.value;

                                    return (
                                        <button
                                            key={stage.value}
                                            type="button"
                                            aria-pressed={selected}
                                            onClick={() =>
                                                form.setData(
                                                    'current_stage',
                                                    stage.value,
                                                )
                                            }
                                            className={cn(
                                                'rounded-xl border p-4 text-left transition-colors',
                                                selected
                                                    ? 'border-primary/50 bg-primary/10'
                                                    : 'border-border/60 bg-muted/10 hover:border-border',
                                            )}
                                        >
                                            <p className="text-sm font-semibold">
                                                {stage.label}
                                            </p>
                                            <p className="mt-1 text-xs font-medium text-muted-foreground">
                                                {STAGE_KIND[stage.value]}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {crewPhaseDescription(
                                                    stage.value,
                                                )}
                                            </p>
                                        </button>
                                    );
                                })}
                            </div>
                            <InputError message={form.errors.current_stage} />
                        </fieldset>
                    ) : (
                        <div className="space-y-2">
                            <Label htmlFor="current_stage">
                                Current Assignment Stage *
                            </Label>
                            <AppSelect
                                value={form.data.current_stage ?? 'p1'}
                                onValueChange={(value) =>
                                    form.setData(
                                        'current_stage',
                                        (value ||
                                            'p1') as CrewAssignmentStartStage,
                                    )
                                }
                                variant="dark"
                                placeholder="Select stage..."
                            >
                                {CREW_DIRECT_START_STAGES.map((stage) => (
                                    <AppSelectItem
                                        key={stage.value}
                                        value={stage.value}
                                    >
                                        {stage.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <p
                                id="current-stage-description"
                                className="text-xs text-muted-foreground"
                            >
                                {crewPhaseDescription(
                                    form.data.current_stage ?? 'p1',
                                )}
                            </p>
                            <InputError message={form.errors.current_stage} />
                        </div>
                    )
                ) : null}
            </div>

            <div className="space-y-2">
                <Label htmlFor="remarks">
                    Remarks{' '}
                    <span className="font-normal text-muted-foreground">
                        (optional)
                    </span>
                </Label>
                <Textarea
                    id="remarks"
                    value={form.data.remarks}
                    onChange={(event) =>
                        form.setData('remarks', event.target.value)
                    }
                    rows={3}
                    placeholder="Optional operational notes..."
                    className="max-h-36 min-h-20"
                />
                <InputError message={form.errors.remarks} />
            </div>
        </section>
    );
}
