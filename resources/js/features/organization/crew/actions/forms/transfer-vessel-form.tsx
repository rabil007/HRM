import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    findPositionTourOption,
    hasManualOverrideInput,
    nextSignoffChoiceForRankChange,
} from '@/features/organization/crew/lib/tour-signoff';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';
import { TourSignoffFields } from './tour-signoff-fields';

export function TransferVesselForm({
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
    schedulingMode,
}: MovementActionFormProps): ReactElement {
    const transferDate = form.data.occurred_at.slice(0, 10);
    const selectedPosition = findPositionTourOption(
        formOptions?.positions,
        form.data.position_id,
    );

    const setDestinationRank = (rankId: number | null): void => {
        const nextPosition = findPositionTourOption(
            formOptions?.positions,
            rankId,
        );
        const planned_signoff_choice = nextSignoffChoiceForRankChange({
            previousChoice: form.data.planned_signoff_choice,
            nextPosition,
            hasManualOverrideInput: hasManualOverrideInput(form.data),
        });

        form.setData({
            ...form.data,
            position_id: rankId,
            planned_signoff_choice,
        });
    };

    const vesselsForClient = (formOptions?.vessels ?? []).filter((vessel) => {
        if (vessel.id === context.vessel_id) {
            return false;
        }

        if (vessel.client_id == null) {
            return false;
        }

        if (form.data.client_id === null) {
            return true;
        }

        return vessel.client_id === form.data.client_id;
    });

    const setDestinationClient = (value: string): void => {
        const nextClientId = value ? Number(value) : null;
        const selectedVessel = formOptions?.vessels.find(
            (vessel) => vessel.id === form.data.vessel_id,
        );
        const vesselMatches =
            selectedVessel == null ||
            (nextClientId !== null &&
                selectedVessel.client_id === nextClientId);

        form.setData({
            ...form.data,
            client_id: nextClientId,
            vessel_id: vesselMatches ? form.data.vessel_id : null,
        });
    };

    const setDestinationVessel = (value: string): void => {
        const nextVesselId = value ? Number(value) : null;
        const selectedVessel = formOptions?.vessels.find(
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
        <div className="space-y-4">
            <div className="space-y-1 rounded-lg border bg-muted/20 p-3 text-sm">
                <div>
                    <span className="text-muted-foreground">Employee: </span>
                    <span className="font-medium">
                        {[context.employee_name, context.employee_no]
                            .filter(Boolean)
                            .join(' · ') || '—'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Current vessel:{' '}
                    </span>
                    <span className="font-medium">
                        {context.vessel_name ?? 'Not set'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Current position:{' '}
                    </span>
                    <span className="font-medium">
                        {context.position_name ?? 'Not set'}
                    </span>
                </div>
            </div>

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    timezone={context.company_timezone}
                    allowFutureActualMovementDates={Boolean(
                        context.allow_future_actual_movement_dates,
                    )}
                    schedulingMode={Boolean(schedulingMode)}
                    inputRef={firstFieldRef}
                />
            ) : null}

            {formOptions ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="transfer-client">
                                Destination client / project (optional)
                            </Label>
                            <Select
                                value={form.data.client_id?.toString() ?? ''}
                                onValueChange={setDestinationClient}
                            >
                                <SelectTrigger id="transfer-client">
                                    <SelectValue placeholder="Select client..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {formOptions.clients.map((client) => (
                                        <SelectItem
                                            key={client.id}
                                            value={client.id.toString()}
                                        >
                                            {client.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.client_id} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="transfer-vessel">
                                Destination vessel{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Select
                                value={form.data.vessel_id?.toString() ?? ''}
                                onValueChange={setDestinationVessel}
                            >
                                <SelectTrigger id="transfer-vessel">
                                    <SelectValue placeholder="Select destination vessel..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {vesselsForClient.map((vessel) => (
                                        <SelectItem
                                            key={vessel.id}
                                            value={vessel.id.toString()}
                                        >
                                            {vessel.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.vessel_id} />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="transfer-position">
                            Destination position{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Select
                            value={form.data.position_id?.toString() ?? ''}
                            onValueChange={(value) =>
                                setDestinationRank(value ? Number(value) : null)
                            }
                        >
                            <SelectTrigger id="transfer-position">
                                <SelectValue placeholder="Select position..." />
                            </SelectTrigger>
                            <SelectContent>
                                {(formOptions?.positions ?? []).map(
                                    (position) => (
                                        <SelectItem
                                            key={position.id}
                                            value={position.id.toString()}
                                        >
                                            {position.name}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.position_id} />
                    </div>
                </>
            ) : null}

            <TourSignoffFields
                form={form}
                selectedPosition={selectedPosition}
                occurredDate={transferDate}
                allowExistingPlan={false}
                idPrefix="transfer"
                tourContextLabel="the destination position"
            />

            <div className="space-y-2">
                <Label htmlFor="transfer-remarks">Remarks (optional)</Label>
                <Textarea
                    id="transfer-remarks"
                    value={form.data.remarks}
                    onChange={(event) =>
                        form.setData('remarks', event.target.value)
                    }
                    rows={3}
                />
                <InputError message={form.errors.remarks} />
            </div>
        </div>
    );
}
