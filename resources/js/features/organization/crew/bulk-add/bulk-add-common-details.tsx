import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { crewPhaseDescription } from '@/features/organization/crew/lib/crew-phase-descriptions';
import type {
    BulkAddCrewFormData,
    CrewAssignmentCreateFormOptions,
    CrewAssignmentStartStage,
} from '@/features/organization/crew/types';
import { CREW_DIRECT_START_STAGES } from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

type CommonDetailsForm = {
    data: BulkAddCrewFormData;
    setData: {
        <K extends keyof BulkAddCrewFormData>(
            key: K,
            value: BulkAddCrewFormData[K],
        ): void;
        (data: BulkAddCrewFormData): void;
    };
    errors: Record<string, string | undefined>;
};

const STAGE_KIND: Record<CrewAssignmentStartStage, string> = {
    p1: 'Default.',
    p0: 'Optional.',
};

export function BulkAddCommonDetails({
    form,
    formOptions,
}: {
    form: CommonDetailsForm;
    formOptions: CrewAssignmentCreateFormOptions;
}) {
    const vesselsForClient = formOptions.vessels.filter((vessel) => {
        if (form.data.vessel_id !== null && vessel.id === form.data.vessel_id) {
            return true;
        }

        if (vessel.client_id == null) {
            return false;
        }

        if (form.data.client_id === null) {
            return true;
        }

        return vessel.client_id === form.data.client_id;
    });

    const setClientId = (value: string): void => {
        const nextClientId = value ? Number(value) : null;
        const selectedVessel = formOptions.vessels.find(
            (vessel) => vessel.id === form.data.vessel_id,
        );
        const vesselMatches =
            selectedVessel != null &&
            selectedVessel.is_active !== false &&
            selectedVessel.client_id != null &&
            nextClientId !== null &&
            selectedVessel.client_id === nextClientId;

        form.setData({
            ...form.data,
            client_id: nextClientId,
            vessel_id: vesselMatches ? form.data.vessel_id : null,
        });
    };

    const setVesselId = (value: string): void => {
        const nextVesselId = value ? Number(value) : null;
        const selectedVessel = formOptions.vessels.find(
            (vessel) => vessel.id === nextVesselId,
        );

        form.setData({
            ...form.data,
            vessel_id: nextVesselId,
            client_id:
                selectedVessel?.client_id != null
                    ? selectedVessel.client_id
                    : form.data.client_id,
        });
    };

    return (
        <section className="space-y-6">
            <div>
                <h2 className="text-sm font-semibold tracking-tight">
                    Common assignment details
                </h2>
                <p className="text-xs text-muted-foreground">
                    These values apply to every crew member in this batch.
                </p>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor="bulk-client">Client</Label>
                    <AppSelect
                        value={form.data.client_id?.toString() ?? ''}
                        onValueChange={setClientId}
                        variant="dark"
                        placeholder="Select client..."
                        searchPlaceholder="Search client..."
                    >
                        <AppSelectItem value="">No client</AppSelectItem>
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
                    <Label htmlFor="bulk-vessel">Vessel</Label>
                    <AppSelect
                        value={form.data.vessel_id?.toString() ?? ''}
                        onValueChange={setVesselId}
                        variant="dark"
                        placeholder="Select vessel..."
                        searchPlaceholder="Search vessel..."
                    >
                        <AppSelectItem value="">No vessel</AppSelectItem>
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
                    <InputError message={form.errors.vessel_id} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="bulk-planned-join">
                        Expected Vessel Join{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </Label>
                    <Input
                        id="bulk-planned-join"
                        type="date"
                        className="h-11"
                        value={form.data.planned_join_at}
                        onChange={(event) =>
                            form.setData('planned_join_at', event.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Expected date the crew members should join the vessel.
                        Actual joining is recorded later through Join Vessel.
                    </p>
                    <InputError message={form.errors.planned_join_at} />
                </div>
            </div>

            <fieldset className="space-y-3">
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
                                    form.setData('current_stage', stage.value)
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
                                    {crewPhaseDescription(stage.value)}
                                </p>
                            </button>
                        );
                    })}
                </div>
                <InputError message={form.errors.current_stage} />
            </fieldset>

            <div className="space-y-2">
                <Label htmlFor="bulk-remarks">
                    Remarks{' '}
                    <span className="font-normal text-muted-foreground">
                        (optional)
                    </span>
                </Label>
                <Textarea
                    id="bulk-remarks"
                    value={form.data.remarks}
                    onChange={(event) =>
                        form.setData('remarks', event.target.value)
                    }
                    rows={3}
                    placeholder="Optional common operational notes..."
                    className="max-h-36 min-h-20"
                />
                <InputError message={form.errors.remarks} />
            </div>
        </section>
    );
}
