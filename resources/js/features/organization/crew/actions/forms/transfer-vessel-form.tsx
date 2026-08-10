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
    findRankTourOption,
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
}: MovementActionFormProps): ReactElement {
    const transferDate = form.data.occurred_at.slice(0, 10);
    const selectedRank = findRankTourOption(
        formOptions?.ranks,
        form.data.rank_id,
    );

    const setDestinationRank = (rankId: number | null): void => {
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
                        Current rank:{' '}
                    </span>
                    <span className="font-medium">
                        {context.rank_name ?? 'Not set'}
                    </span>
                </div>
            </div>

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    inputRef={firstFieldRef}
                />
            ) : null}

            {formOptions ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="transfer-vessel">
                                Destination vessel{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Select
                                value={form.data.vessel_id?.toString() ?? ''}
                                onValueChange={(value) =>
                                    form.setData(
                                        'vessel_id',
                                        value ? Number(value) : null,
                                    )
                                }
                            >
                                <SelectTrigger id="transfer-vessel">
                                    <SelectValue placeholder="Select destination vessel..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {formOptions.vessels
                                        .filter(
                                            (vessel) =>
                                                vessel.id !== context.vessel_id,
                                        )
                                        .map((vessel) => (
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
                        <div className="space-y-2">
                            <Label htmlFor="transfer-rank">
                                Destination rank{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Select
                                value={form.data.rank_id?.toString() ?? ''}
                                onValueChange={(value) =>
                                    setDestinationRank(
                                        value ? Number(value) : null,
                                    )
                                }
                            >
                                <SelectTrigger id="transfer-rank">
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
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="transfer-client">
                                Destination client / project (optional)
                            </Label>
                            <Select
                                value={form.data.client_id?.toString() ?? ''}
                                onValueChange={(value) =>
                                    form.setData(
                                        'client_id',
                                        value ? Number(value) : null,
                                    )
                                }
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
                            <Label htmlFor="transfer-visa">
                                Visa type (optional)
                            </Label>
                            <Select
                                value={
                                    form.data.company_visa_type_id?.toString() ??
                                    ''
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'company_visa_type_id',
                                        value ? Number(value) : null,
                                    )
                                }
                            >
                                <SelectTrigger id="transfer-visa">
                                    <SelectValue placeholder="Select visa type..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {formOptions.visa_types.map((visaType) => (
                                        <SelectItem
                                            key={visaType.id}
                                            value={visaType.id.toString()}
                                        >
                                            {visaType.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={form.errors.company_visa_type_id}
                            />
                        </div>
                    </div>
                </>
            ) : null}

            <TourSignoffFields
                form={form}
                selectedRank={selectedRank}
                occurredDate={transferDate}
                allowExistingPlan={false}
                idPrefix="transfer"
                tourContextLabel="the destination rank"
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
