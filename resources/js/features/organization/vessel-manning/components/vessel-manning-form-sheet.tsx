import type { InertiaFormProps } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type {
    RankOption,
    VesselManningFormData,
    VesselManningItem,
} from '../types';

function emptyRequirementRow(): VesselManningFormData['requirements'][number] {
    return {
        rank_id: '',
        required_count: '1',
    };
}

export function VesselManningFormSheet({
    open,
    onOpenChange,
    vessel,
    ranks,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    vessel: VesselManningItem | null;
    ranks: RankOption[];
    form: InertiaFormProps<VesselManningFormData>;
    onSubmit: () => void;
}) {
    const requirements = form.data.requirements;

    const setRequirements = (
        next: VesselManningFormData['requirements'],
    ): void => {
        form.setData('requirements', next);
    };

    const addRow = (): void => {
        setRequirements([...requirements, emptyRequirementRow()]);
    };

    const removeRow = (index: number): void => {
        setRequirements(
            requirements.filter((_, rowIndex) => rowIndex !== index),
        );
    };

    const updateRow = (
        index: number,
        field: keyof VesselManningFormData['requirements'][number],
        value: string,
    ): void => {
        setRequirements(
            requirements.map((row, rowIndex) =>
                rowIndex === index ? { ...row, [field]: value } : row,
            ),
        );
    };

    const usedRankIds = new Set(
        requirements
            .map((row) => row.rank_id)
            .filter((rankId) => rankId !== ''),
    );

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        Edit vessel manning
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {vessel
                            ? `Set required ranks and headcount for ${vessel.name}.`
                            : 'Set required ranks and headcount for this vessel.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto p-8">
                    {vessel ? (
                        <div className="rounded-xl border border-border/60 bg-muted/30 p-4">
                            <div className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Vessel
                            </div>
                            <div className="mt-1 text-base font-semibold">
                                {vessel.name}
                            </div>
                            {vessel.vessel_type_name ? (
                                <div className="mt-1 text-sm text-muted-foreground">
                                    {vessel.vessel_type_name}
                                </div>
                            ) : null}
                        </div>
                    ) : null}

                    <div className="space-y-4">
                        <div className="flex items-center justify-between gap-3">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Rank requirements
                            </Label>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addRow}
                                className="rounded-xl border-primary/25 bg-primary/5 text-primary transition-colors hover:bg-primary/10"
                            >
                                <Plus className="mr-1.5 h-4 w-4" />
                                Add rank
                            </Button>
                        </div>

                        {requirements.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground">
                                No ranks configured yet. Add a rank to define
                                this vessel&apos;s manning.
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {requirements.map((row, index) => {
                                    const rankError =
                                        form.errors[
                                            `requirements.${index}.rank_id` as keyof typeof form.errors
                                        ];
                                    const countError =
                                        form.errors[
                                            `requirements.${index}.required_count` as keyof typeof form.errors
                                        ];

                                    return (
                                        <div
                                            key={`requirement-${index}`}
                                            className="grid grid-cols-[1fr_120px_auto] items-start gap-3 rounded-xl border border-border/60 bg-muted/20 p-4 transition-all duration-200 hover:border-border/80 hover:bg-muted/30"
                                        >
                                            <div className="space-y-2">
                                                <Label className="text-[11px] font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                                    Rank
                                                </Label>
                                                <AppSelect
                                                    value={row.rank_id}
                                                    onValueChange={(value) =>
                                                        updateRow(
                                                            index,
                                                            'rank_id',
                                                            value,
                                                        )
                                                    }
                                                    placeholder="Select rank"
                                                    variant="card"
                                                >
                                                    {ranks.map((rank) => (
                                                        <AppSelectItem
                                                            key={rank.id}
                                                            value={String(
                                                                rank.id,
                                                            )}
                                                            disabled={
                                                                usedRankIds.has(
                                                                    String(
                                                                        rank.id,
                                                                    ),
                                                                ) &&
                                                                row.rank_id !==
                                                                    String(
                                                                        rank.id,
                                                                    )
                                                            }
                                                        >
                                                            {rank.name}
                                                        </AppSelectItem>
                                                    ))}
                                                </AppSelect>
                                                {rankError ? (
                                                    <div className="text-xs font-medium text-destructive">
                                                        {rankError}
                                                    </div>
                                                ) : null}
                                            </div>

                                            <div className="space-y-2">
                                                <Label className="text-[11px] font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                                    Required
                                                </Label>
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    max={9999}
                                                    value={row.required_count}
                                                    onChange={(event) =>
                                                        updateRow(
                                                            index,
                                                            'required_count',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="h-11 rounded-xl"
                                                />
                                                {countError ? (
                                                    <div className="text-xs font-medium text-destructive">
                                                        {countError}
                                                    </div>
                                                ) : null}
                                            </div>

                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="mt-7 shrink-0 rounded-lg text-muted-foreground transition-colors duration-200 hover:bg-destructive/10 hover:text-destructive"
                                                aria-label="Remove rank row"
                                                onClick={() => removeRow(index)}
                                            >
                                                <Minus className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    );
                                })}
                            </div>
                        )}

                        {form.errors.requirements ? (
                            <div className="text-xs font-medium text-destructive">
                                {form.errors.requirements}
                            </div>
                        ) : null}
                    </div>
                </div>

                <div className="flex items-center justify-end gap-3 border-t border-border/60 p-8">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={form.processing}
                        onClick={onSubmit}
                    >
                        Save manning
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
