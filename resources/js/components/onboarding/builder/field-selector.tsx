import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

type FieldOption = { key: string; label: string };
type FieldRequirement = { key: string; required: boolean };

export function FieldSelector({
    title,
    options,
    selectedFields,
    otherStagesFields,
    otherStageLabels,
    onUpdate,
    onSortClick,
}: {
    title: string;
    options: readonly FieldOption[];
    selectedFields: FieldRequirement[];
    otherStagesFields: Set<string>;
    otherStageLabels?: Map<string, string>;
    onUpdate: (next: FieldRequirement[]) => void;
    onSortClick: () => void;
}) {
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        return options.filter((f) => f.label.toLowerCase().includes(search.toLowerCase()));
    }, [options, search]);

    const orderByKey = useMemo(() => {
        return new Map(selectedFields.map((sf, i) => [sf.key, i + 1] as const));
    }, [selectedFields]);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-[200px] shrink-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-semibold leading-none text-foreground">{title}</span>
                        <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold tabular-nums text-muted-foreground">
                            {selectedFields.length} selected
                        </span>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2 sm:gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 rounded-lg px-2 text-xs"
                        disabled={selectedFields.length < 2}
                        onClick={onSortClick}
                    >
                        Reorder
                    </Button>
                    <Input
                        placeholder="Filter list…"
                        className="h-8 w-full rounded-lg bg-background text-xs sm:w-40"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-6 text-[10px] px-2 text-muted-foreground hover:text-primary uppercase tracking-wider font-semibold"
                        onClick={() => {
                            const available = options
                                .filter((f) => f.label.toLowerCase().includes(search.toLowerCase()))
                                .filter((f) => !otherStagesFields.has(f.key));

                            const currentlySelectedInSearch = selectedFields.filter((sf) =>
                                available.some((a) => a.key === sf.key),
                            );

                            if (currentlySelectedInSearch.length === available.length && available.length > 0) {
                                onUpdate(selectedFields.filter((sf) => !available.some((a) => a.key === sf.key)));

                                return;
                            }

                            const toAdd = available
                                .filter((a) => !selectedFields.some((sf) => sf.key === a.key))
                                .map((a) => ({ key: a.key, required: true }));

                            onUpdate([...selectedFields, ...toAdd]);
                        }}
                    >
                        {selectedFields.length === options.filter((f) => !otherStagesFields.has(f.key)).length &&
                        selectedFields.length > 0
                            ? 'Deselect All'
                            : 'Select All'}
                    </Button>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-2 p-3 rounded-xl border border-border/50 bg-card/30 max-h-[350px] overflow-y-auto">
                {visible.length === 0 ? (
                    <div className="py-8 text-center text-xs text-muted-foreground">
                        {search.trim() !== '' ? 'No fields match your filter.' : 'No fields available.'}
                    </div>
                ) : (
                    visible.map((f) => {
                        const isSelected = selectedFields.some((sf) => sf.key === f.key);
                        const reqData = selectedFields.find((sf) => sf.key === f.key);
                        const usedOnOtherStep =
                            !isSelected && otherStagesFields.has(f.key);
                        const otherStepLabel = otherStageLabels?.get(f.key);

                        return (
                            <div
                                key={f.key}
                                className={`flex flex-col p-2.5 rounded-lg border transition-all ${
                                    isSelected
                                        ? 'border-primary/50 bg-primary/5'
                                        : usedOnOtherStep
                                          ? 'border-border/40 bg-muted/20 opacity-80'
                                          : 'border-border/50 bg-card/30'
                                }`}
                            >
                                <div className="flex items-center justify-between">
                                    <label
                                        className={`flex flex-1 flex-wrap items-center gap-2.5 text-sm group ${
                                            usedOnOtherStep ? 'cursor-not-allowed' : 'cursor-pointer'
                                        }`}
                                    >
                                        <input
                                            type="checkbox"
                                            className="rounded border-border/50 text-primary w-4 h-4 focus:ring-primary/20 disabled:cursor-not-allowed"
                                            checked={isSelected}
                                            disabled={usedOnOtherStep}
                                            onChange={(e) => {
                                                const next = e.target.checked;
                                                onUpdate(
                                                    next
                                                        ? [...selectedFields, { key: f.key, required: true }]
                                                        : selectedFields.filter((k) => k.key !== f.key),
                                                );
                                            }}
                                        />
                                        {isSelected && (
                                            <span className="text-[10px] font-bold tabular-nums text-primary bg-primary/10 border border-primary/20 rounded-md px-1.5 py-0.5">
                                                #{orderByKey.get(f.key)}
                                            </span>
                                        )}
                                        <span
                                            className={`font-medium transition-colors ${
                                                usedOnOtherStep
                                                    ? 'text-muted-foreground'
                                                    : 'group-hover:text-primary'
                                            }`}
                                        >
                                            {f.label}
                                        </span>
                                        {usedOnOtherStep && otherStepLabel ? (
                                            <span className="text-[10px] font-medium text-muted-foreground">
                                                On step: {otherStepLabel}
                                            </span>
                                        ) : null}
                                    </label>
                                    {isSelected && (
                                        <div className="flex items-center gap-2 pl-3 border-l border-border/60">
                                            <span
                                                className={`text-[10px] uppercase font-bold ${
                                                    reqData?.required ? 'text-primary' : 'text-muted-foreground'
                                                }`}
                                            >
                                                {reqData?.required ? 'Req' : 'Opt'}
                                            </span>
                                            <Switch
                                                checked={reqData?.required ?? true}
                                                onCheckedChange={(val) => {
                                                    onUpdate(
                                                        selectedFields.map((sf) =>
                                                            sf.key === f.key ? { ...sf, required: val } : sf,
                                                        ),
                                                    );
                                                }}
                                                className="scale-75 data-[state=checked]:bg-primary data-[state=unchecked]:bg-muted-foreground/30"
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })
                )}
            </div>
        </div>
    );
}

