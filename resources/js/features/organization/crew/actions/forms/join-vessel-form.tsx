import type { ReactElement } from 'react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatDisplayDate } from '@/lib/format-date';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';
import { TourSignoffFields } from './tour-signoff-fields';

export function JoinVesselForm({
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const joinDate = form.data.occurred_at.slice(0, 10);
    const preJoinAccommodation = context.pre_join_accommodation;
    const selectedRank = formOptions?.ranks.find(
        (rank) => rank.id === form.data.rank_id,
    );

    const lastAutoCheckOutDateRef = useRef(
        form.data.check_out_date || form.data.occurred_at.slice(0, 10),
    );

    const syncCheckOutDate = (occurredAt: string): void => {
        if (preJoinAccommodation?.status !== 'open_hotel') {
            return;
        }

        const nextJoinDate = occurredAt.slice(0, 10);

        if (
            !nextJoinDate ||
            (form.data.check_out_date !== '' &&
                form.data.check_out_date !== lastAutoCheckOutDateRef.current)
        ) {
            return;
        }

        form.setData('check_out_date', nextJoinDate);
        lastAutoCheckOutDateRef.current = nextJoinDate;
    };

    const vesselsForClient = (formOptions?.vessels ?? []).filter((vessel) => {
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

    const setVesselId = (value: string): void => {
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
                        Current phase:{' '}
                    </span>
                    <span className="font-medium">
                        {context.current_phase_code
                            ? `${context.current_phase_code.toUpperCase()} · ${context.current_phase_label ?? ''}`
                            : 'None'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Current vessel plan:{' '}
                    </span>
                    <span className="font-medium">
                        {context.vessel_name ?? 'Not set'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Current rank:{' '}
                    </span>
                    <span className="font-medium">
                        {context.rank_name ?? 'Not set'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Expected join:{' '}
                    </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.planned_join_at)}
                    </span>
                </div>
            </div>

            {preJoinAccommodation?.status === 'open_hotel' ? (
                <div className="space-y-4 rounded-lg border border-border/60 p-4">
                    <div>
                        <h3 className="text-sm font-semibold">
                            Current Accommodation
                        </h3>
                    </div>
                    <div className="space-y-2 text-sm">
                        <div className="font-medium">
                            {preJoinAccommodation.hotel_name ?? 'Hotel'}
                        </div>
                        {preJoinAccommodation.room_type_name ? (
                            <div className="text-muted-foreground">
                                {preJoinAccommodation.room_type_name}
                            </div>
                        ) : null}
                        <div className="text-muted-foreground">
                            Checked in{' '}
                            {formatDisplayDate(
                                preJoinAccommodation.check_in_date,
                            )}
                        </div>
                        {preJoinAccommodation.stay_days !== null ? (
                            <div className="text-muted-foreground">
                                Stay {preJoinAccommodation.stay_days} day
                                {preJoinAccommodation.stay_days === 1
                                    ? ''
                                    : 's'}
                            </div>
                        ) : null}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="movement-check-out-date">
                            Hotel Check-out Date{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="movement-check-out-date"
                            type="date"
                            value={form.data.check_out_date}
                            onChange={(event) =>
                                form.setData(
                                    'check_out_date',
                                    event.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.check_out_date} />
                    </div>
                </div>
            ) : null}

            {preJoinAccommodation?.status === 'no_accommodation' ? (
                <div className="rounded-lg border border-border/60 bg-muted/20 p-3 text-sm">
                    <div className="font-medium">Accommodation</div>
                    <p className="mt-1 text-muted-foreground">
                        No hotel accommodation recorded.
                    </p>
                </div>
            ) : null}

            {preJoinAccommodation?.status === 'missing' &&
            preJoinAccommodation.warning ? (
                <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200">
                    {preJoinAccommodation.warning}
                </div>
            ) : null}

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    timezone={context.company_timezone}
                    allowFutureActualMovementDates={Boolean(
                        context.allow_future_actual_movement_dates,
                    )}
                    inputRef={
                        preJoinAccommodation?.status === 'open_hotel'
                            ? undefined
                            : firstFieldRef
                    }
                    onValueChange={syncCheckOutDate}
                />
            ) : null}

            {formOptions ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="movement-client">
                                Client (optional)
                            </Label>
                            <Select
                                value={form.data.client_id?.toString() ?? ''}
                                onValueChange={setClientId}
                            >
                                <SelectTrigger id="movement-client">
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
                            <Label htmlFor="movement-vessel">
                                Vessel{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Select
                                value={form.data.vessel_id?.toString() ?? ''}
                                onValueChange={setVesselId}
                            >
                                <SelectTrigger id="movement-vessel">
                                    <SelectValue placeholder="Select vessel..." />
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
                            <p className="text-xs text-muted-foreground">
                                {form.data.client_id !== null &&
                                vesselsForClient.length === 0
                                    ? 'No vessels are assigned to this client.'
                                    : 'The vessel on which the employee physically joins.'}
                            </p>
                            <InputError message={form.errors.vessel_id} />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="movement-rank">
                            Rank <span className="text-destructive">*</span>
                        </Label>
                        <Select
                            value={form.data.rank_id?.toString() ?? ''}
                            onValueChange={(value) =>
                                form.setData(
                                    'rank_id',
                                    value ? Number(value) : null,
                                )
                            }
                        >
                            <SelectTrigger id="movement-rank">
                                <SelectValue placeholder="Select rank..." />
                            </SelectTrigger>
                            <SelectContent>
                                {formOptions.ranks.map((rank) => (
                                    <SelectItem
                                        key={rank.id}
                                        value={rank.id.toString()}
                                    >
                                        {rank.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            The rank served onboard. This is used for Planning
                            and Sea Service.
                        </p>
                        <InputError message={form.errors.rank_id} />
                    </div>
                </>
            ) : null}

            <TourSignoffFields
                form={form}
                selectedRank={selectedRank}
                occurredDate={joinDate}
                existingPlannedSignoffAt={context.planned_signoff_at}
                allowExistingPlan
                idPrefix="movement"
                tourContextLabel="this rank"
            />

            <div className="space-y-2">
                <Label htmlFor="movement-join-remarks">
                    Remarks (optional)
                </Label>
                <Textarea
                    id="movement-join-remarks"
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
