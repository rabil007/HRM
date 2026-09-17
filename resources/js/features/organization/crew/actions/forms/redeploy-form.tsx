import type { ReactElement } from 'react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
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
import { roomTypesForHotel } from '@/features/organization/crew/lib/accommodation-room-types';
import { resolveDestinationCheckInDateOnP2AEntry } from '@/features/organization/crew/lib/redeploy-destination-check-in';
import {
    clearedDirectP4TourFields,
    defaultDestinationTourSignoffChoice,
    findRankTourOption,
    hasManualOverrideInput,
    nextSignoffChoiceForRankChange,
} from '@/features/organization/crew/lib/tour-signoff';
import { formatDisplayDate } from '@/lib/format-date';
import { CREW_PHASE_LABELS } from '../../types';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';
import { TourSignoffFields } from './tour-signoff-fields';

const REDEPLOY_STARTING_PHASES = [
    { value: 'p0', label: CREW_PHASE_LABELS.p0 },
    { value: 'p2a', label: CREW_PHASE_LABELS.p2a },
    { value: 'p4', label: CREW_PHASE_LABELS.p4 },
] as const;

export function RedeployForm({
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const startingPhase = form.data.starting_phase;
    const requiresVessel = startingPhase === 'p4';
    const redeployDate = form.data.occurred_at.slice(0, 10);
    const showDestinationFields = ['p2a', 'p4'].includes(startingPhase);
    const showDirectP4Tour = startingPhase === 'p4';
    const showForecastSignoff = startingPhase === 'p2a';
    const showDestinationAccommodation = startingPhase === 'p2a';
    const postSignoffAccommodation = context.post_signoff_accommodation;
    const noDestinationHotelAccommodation = form.data.no_hotel_accommodation;
    const availableRoomTypes = roomTypesForHotel(
        formOptions?.room_types,
        form.data.hotel_id,
    );
    const lastAutoSourceCheckOutDateRef = useRef(
        form.data.source_check_out_date || form.data.occurred_at.slice(0, 10),
    );
    const lastAutoDestinationCheckInDateRef = useRef(
        form.data.check_in_date || form.data.occurred_at.slice(0, 10),
    );

    const syncSourceCheckOutDate = (occurredAt: string): void => {
        if (postSignoffAccommodation?.status !== 'open_hotel') {
            return;
        }

        const nextRedeployDate = occurredAt.slice(0, 10);

        if (
            !nextRedeployDate ||
            (form.data.source_check_out_date !== '' &&
                form.data.source_check_out_date !==
                    lastAutoSourceCheckOutDateRef.current)
        ) {
            return;
        }

        form.setData('source_check_out_date', nextRedeployDate);
        lastAutoSourceCheckOutDateRef.current = nextRedeployDate;
    };

    const syncDestinationCheckInDate = (occurredAt: string): void => {
        if (!showDestinationAccommodation || noDestinationHotelAccommodation) {
            return;
        }

        const nextRedeployDate = occurredAt.slice(0, 10);

        if (
            !nextRedeployDate ||
            (form.data.check_in_date !== '' &&
                form.data.check_in_date !==
                    lastAutoDestinationCheckInDateRef.current)
        ) {
            return;
        }

        form.setData('check_in_date', nextRedeployDate);
        lastAutoDestinationCheckInDateRef.current = nextRedeployDate;
    };

    const setNoDestinationHotelAccommodation = (checked: boolean): void => {
        const nextCheckInDate = checked
            ? ''
            : form.data.occurred_at.slice(0, 10);

        if (!checked) {
            lastAutoDestinationCheckInDateRef.current = nextCheckInDate;
        }

        form.setData({
            ...form.data,
            no_hotel_accommodation: checked,
            accommodation_status: checked ? 'no_accommodation' : 'hotel',
            hotel_id: checked ? null : form.data.hotel_id,
            room_type_id: checked ? null : form.data.room_type_id,
            check_in_date: nextCheckInDate,
        });
    };
    const selectedRank = findRankTourOption(
        formOptions?.ranks,
        form.data.rank_id,
    );
    const signoffBeforeRedeploy =
        form.data.planned_signoff_at &&
        redeployDate &&
        form.data.planned_signoff_at < redeployDate;

    const setDestinationRank = (rankId: number | null): void => {
        if (!showDirectP4Tour) {
            form.setData('rank_id', rankId);

            return;
        }

        const nextRank = findRankTourOption(formOptions?.ranks, rankId);
        const planned_signoff_choice = nextSignoffChoiceForRankChange({
            previousChoice: form.data.planned_signoff_choice,
            nextRank,
            hasManualOverrideInput: hasManualOverrideInput(form.data),
        });

        form.setData({
            ...form.data,
            rank_id: rankId,
            planned_signoff_choice,
        });
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
                        Previous vessel:{' '}
                    </span>
                    <span className="font-medium">
                        {context.vessel_name ?? 'Not set'}
                    </span>
                </div>
            </div>

            <div className="space-y-2">
                <Label htmlFor="redeploy-starting-phase">
                    Starting phase <span className="text-destructive">*</span>
                </Label>
                <Select
                    value={startingPhase}
                    onValueChange={(value) => {
                        const next: Partial<typeof form.data> = {
                            starting_phase: value,
                        };

                        if (value === 'p0') {
                            next.vessel_id = null;
                            next.rank_id = null;
                            next.client_id = null;
                            next.planned_signoff_at = '';
                            Object.assign(next, clearedDirectP4TourFields());
                        } else if (value === 'p4') {
                            if (!['p2a', 'p4'].includes(startingPhase)) {
                                next.vessel_id = context.vessel_id;
                                next.rank_id = context.rank_id;
                                next.client_id = context.client_id;
                            }

                            const rankId =
                                next.rank_id !== undefined
                                    ? next.rank_id
                                    : form.data.rank_id;
                            const rank = findRankTourOption(
                                formOptions?.ranks,
                                rankId,
                            );

                            next.planned_signoff_choice =
                                defaultDestinationTourSignoffChoice(rank);
                            next.planned_signoff_override_reason = '';
                            next.planned_signoff_at = '';
                        } else if (value === 'p2a') {
                            if (!['p1', 'p2a', 'p4'].includes(startingPhase)) {
                                next.vessel_id = context.vessel_id;
                                next.rank_id = context.rank_id;
                                next.client_id = context.client_id;
                            }

                            Object.assign(next, clearedDirectP4TourFields());

                            const syncedCheckIn =
                                resolveDestinationCheckInDateOnP2AEntry({
                                    redeployDate: form.data.occurred_at.slice(
                                        0,
                                        10,
                                    ),
                                    currentCheckInDate: form.data.check_in_date,
                                    lastAutoCheckInDate:
                                        lastAutoDestinationCheckInDateRef.current,
                                    noHotelAccommodation:
                                        form.data.no_hotel_accommodation,
                                });

                            if (syncedCheckIn !== null) {
                                next.check_in_date = syncedCheckIn.checkInDate;
                                lastAutoDestinationCheckInDateRef.current =
                                    syncedCheckIn.lastAutoCheckInDate;
                            }
                        } else {
                            if (!['p1', 'p2a', 'p4'].includes(startingPhase)) {
                                next.vessel_id = context.vessel_id;
                                next.rank_id = context.rank_id;
                                next.client_id = context.client_id;
                            }

                            Object.assign(next, clearedDirectP4TourFields());
                        }

                        form.setData({ ...form.data, ...next });
                    }}
                >
                    <SelectTrigger id="redeploy-starting-phase">
                        <SelectValue placeholder="Select starting phase..." />
                    </SelectTrigger>
                    <SelectContent>
                        {REDEPLOY_STARTING_PHASES.map((phase) => (
                            <SelectItem key={phase.value} value={phase.value}>
                                {phase.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                    Only the chosen starting phase is created. Earlier phases
                    are not invented.
                </p>
                <InputError message={form.errors.starting_phase} />
            </div>

            {postSignoffAccommodation?.status === 'open_hotel' ? (
                <div className="space-y-4 rounded-lg border border-border/60 p-4">
                    <div>
                        <h3 className="text-sm font-semibold">
                            Current Post-Sign-Off Accommodation
                        </h3>
                    </div>
                    <div className="space-y-2 text-sm">
                        <div className="font-medium">
                            {postSignoffAccommodation.hotel_name ?? 'Hotel'}
                        </div>
                        {postSignoffAccommodation.room_type_name ? (
                            <div className="text-muted-foreground">
                                {postSignoffAccommodation.room_type_name}
                            </div>
                        ) : null}
                        <div className="text-muted-foreground">
                            Checked in{' '}
                            {formatDisplayDate(
                                postSignoffAccommodation.check_in_date,
                            )}
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="redeploy-source-check-out-date">
                            Source Hotel Check-out Date{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="redeploy-source-check-out-date"
                            type="date"
                            value={form.data.source_check_out_date}
                            onChange={(event) =>
                                form.setData(
                                    'source_check_out_date',
                                    event.target.value,
                                )
                            }
                        />
                        <InputError
                            message={form.errors.source_check_out_date}
                        />
                    </div>
                </div>
            ) : null}

            {postSignoffAccommodation?.status === 'missing' &&
            postSignoffAccommodation.warning ? (
                <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200">
                    {postSignoffAccommodation.warning}
                </div>
            ) : null}

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    inputRef={
                        postSignoffAccommodation?.status === 'open_hotel'
                            ? undefined
                            : firstFieldRef
                    }
                    onValueChange={(occurredAt) => {
                        syncSourceCheckOutDate(occurredAt);
                        syncDestinationCheckInDate(occurredAt);
                    }}
                />
            ) : null}

            {startingPhase === 'p0' ? (
                <div className="space-y-2">
                    <Label htmlFor="redeploy-planned-arrival-at">
                        Planned Arrival Date (optional)
                    </Label>
                    <Input
                        id="redeploy-planned-arrival-at"
                        type="date"
                        value={form.data.planned_arrival_at ?? ''}
                        onChange={(e) =>
                            form.setData('planned_arrival_at', e.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Expected date the crew member will arrive at the joining
                        location. Actual arrival is recorded later through
                        Record Arrival.
                    </p>
                    <InputError message={form.errors.planned_arrival_at} />
                </div>
            ) : null}

            {formOptions && showDestinationFields ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="redeploy-client">
                                Destination client / project (optional)
                            </Label>
                            <Select
                                value={form.data.client_id?.toString() ?? ''}
                                onValueChange={setDestinationClient}
                            >
                                <SelectTrigger id="redeploy-client">
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
                            <Label htmlFor="redeploy-vessel">
                                Destination vessel
                                {requiresVessel ? (
                                    <span className="text-destructive"> *</span>
                                ) : (
                                    ' (optional)'
                                )}
                            </Label>
                            <Select
                                value={form.data.vessel_id?.toString() ?? ''}
                                onValueChange={setDestinationVessel}
                            >
                                <SelectTrigger id="redeploy-vessel">
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
                            <InputError message={form.errors.vessel_id} />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="redeploy-rank">
                            Destination rank
                            {requiresVessel ? (
                                <span className="text-destructive"> *</span>
                            ) : (
                                ' (optional)'
                            )}
                        </Label>
                        <Select
                            value={form.data.rank_id?.toString() ?? ''}
                            onValueChange={(value) =>
                                setDestinationRank(value ? Number(value) : null)
                            }
                        >
                            <SelectTrigger id="redeploy-rank">
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
                        <InputError message={form.errors.rank_id} />
                    </div>
                </>
            ) : null}

            {showDirectP4Tour ? (
                <TourSignoffFields
                    form={form}
                    selectedRank={selectedRank}
                    occurredDate={redeployDate}
                    allowExistingPlan={false}
                    idPrefix="redeploy"
                    tourContextLabel="the destination rank"
                />
            ) : null}

            {showDestinationAccommodation && formOptions ? (
                <div className="space-y-4 rounded-lg border border-border/60 p-4">
                    <div>
                        <h3 className="text-sm font-semibold">
                            Destination Pre-Join Accommodation
                        </h3>
                    </div>

                    {!noDestinationHotelAccommodation ? (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="redeploy-destination-hotel">
                                    Hotel{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Select
                                    value={form.data.hotel_id?.toString() ?? ''}
                                    onValueChange={(value) =>
                                        form.setData({
                                            ...form.data,
                                            hotel_id: value
                                                ? Number(value)
                                                : null,
                                            room_type_id: null,
                                        })
                                    }
                                >
                                    <SelectTrigger id="redeploy-destination-hotel">
                                        <SelectValue placeholder="Select hotel..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(formOptions.hotels ?? []).map(
                                            (hotel) => (
                                                <SelectItem
                                                    key={hotel.id}
                                                    value={hotel.id.toString()}
                                                >
                                                    {hotel.name}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.hotel_id} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="redeploy-destination-room-type">
                                    Room Type
                                </Label>
                                <Select
                                    value={
                                        form.data.room_type_id?.toString() ??
                                        '__none__'
                                    }
                                    onValueChange={(value) =>
                                        form.setData(
                                            'room_type_id',
                                            value === '__none__'
                                                ? null
                                                : Number(value),
                                        )
                                    }
                                >
                                    <SelectTrigger id="redeploy-destination-room-type">
                                        <SelectValue placeholder="Not assigned yet" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">
                                            Not assigned yet
                                        </SelectItem>
                                        {availableRoomTypes.map((roomType) => (
                                            <SelectItem
                                                key={roomType.id}
                                                value={roomType.id.toString()}
                                            >
                                                {roomType.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError
                                    message={form.errors.room_type_id}
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="redeploy-destination-check-in-date">
                                    Check-in Date{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="redeploy-destination-check-in-date"
                                    type="date"
                                    value={form.data.check_in_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'check_in_date',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.check_in_date}
                                />
                            </div>
                        </>
                    ) : null}

                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="redeploy-no-destination-hotel"
                            checked={noDestinationHotelAccommodation}
                            onCheckedChange={(checked) =>
                                setNoDestinationHotelAccommodation(
                                    checked === true,
                                )
                            }
                        />
                        <div className="space-y-1">
                            <Label
                                htmlFor="redeploy-no-destination-hotel"
                                className="font-normal"
                            >
                                No hotel accommodation
                            </Label>
                        </div>
                    </div>
                    <InputError message={form.errors.accommodation_status} />
                </div>
            ) : null}

            {showForecastSignoff ? (
                <div className="space-y-2">
                    <Label htmlFor="redeploy-planned-signoff">
                        Planned Sign-Off (optional)
                    </Label>
                    <Input
                        id="redeploy-planned-signoff"
                        type="date"
                        value={form.data.planned_signoff_at}
                        min={redeployDate || undefined}
                        onChange={(event) =>
                            form.setData(
                                'planned_signoff_at',
                                event.target.value,
                            )
                        }
                    />
                    {signoffBeforeRedeploy ? (
                        <p className="text-sm text-destructive">
                            The planned sign-off cannot be before the
                            redeployment date.
                        </p>
                    ) : null}
                    <p className="text-xs text-muted-foreground">
                        Forecast only. Tour of Duty is set when the assignment
                        joins the vessel.
                    </p>
                    <InputError message={form.errors.planned_signoff_at} />
                </div>
            ) : null}

            <div className="space-y-2">
                <Label htmlFor="redeploy-remarks">Remarks (optional)</Label>
                <Textarea
                    id="redeploy-remarks"
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
