import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

type FieldOption = { key: string; label: string };
type FieldRequirement = { key: string; required: boolean };

export function FieldSelector({
    title,
    options,
    selectedFields,
    otherStagesFields,
    onUpdate,
    onSortClick,
}: {
    title: string;
    options: readonly FieldOption[];
    selectedFields: FieldRequirement[];
    otherStagesFields: Set<string>;
    onUpdate: (next: FieldRequirement[]) => void;
    onSortClick: () => void;
}) {
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        return options
            .filter((f) => f.label.toLowerCase().includes(search.toLowerCase()))
            .filter((f) => !otherStagesFields.has(f.key) || selectedFields.some((sf) => sf.key === f.key));
    }, [options, otherStagesFields, search, selectedFields]);

    const orderByKey = useMemo(() => {
        return new Map(selectedFields.map((sf, i) => [sf.key, i + 1] as const));
    }, [selectedFields]);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Label className="text-sm font-medium flex items-center gap-2">
                    {title}
                    <span className="text-[10px] text-primary/80 font-mono py-0.5 px-1.5 rounded-md bg-primary/10">
                        {selectedFields.length} sel
                    </span>
                </Label>
                <div className="flex flex-wrap items-center gap-2 sm:gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7 text-[10px] px-2 rounded-lg"
                        disabled={selectedFields.length < 2}
                        onClick={onSortClick}
                    >
                        Sort
                    </Button>
                    <Input
                        placeholder="Search..."
                        className="h-7 text-[10px] w-full sm:w-32 rounded-lg bg-card/30"
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
                    <div className="py-8 text-center text-xs text-muted-foreground">No fields found.</div>
                ) : (
                    visible.map((f) => {
                        const isSelected = selectedFields.some((sf) => sf.key === f.key);
                        const reqData = selectedFields.find((sf) => sf.key === f.key);

                        return (
                            <div
                                key={f.key}
                                className={`flex flex-col p-2.5 rounded-lg border transition-all ${
                                    isSelected ? 'border-primary/50 bg-primary/5' : 'border-border/50 bg-card/30'
                                }`}
                            >
                                <div className="flex items-center justify-between">
                                    <label className="flex items-center gap-2.5 text-sm cursor-pointer group flex-1">
                                        <input
                                            type="checkbox"
                                            className="rounded border-border/50 text-primary w-4 h-4 focus:ring-primary/20"
                                            checked={isSelected}
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
                                        <span className="group-hover:text-primary transition-colors font-medium">
                                            {f.label}
                                        </span>
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

