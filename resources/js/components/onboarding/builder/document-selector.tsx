import { ArrowDown, ArrowUp, GripVertical } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { toast } from '@/lib/toast';
import type { DocsRequirement, DocumentTypeModel } from '@/pages/onboarding/template-form';

type SortState = {
    open: boolean;
    list: DocsRequirement[];
    draggingType: string | null;
};

export function DocumentSelector({
    selectedDocs,
    documentTypes,
    otherStagesDocs,
    onUpdate,
}: {
    selectedDocs: DocsRequirement[];
    documentTypes: DocumentTypeModel[];
    otherStagesDocs: Set<string>;
    onUpdate: (docs: DocsRequirement[]) => void;
}) {
    const [docSearch, setDocSearch] = useState('');
    const [sort, setSort] = useState<SortState>({ open: false, list: [], draggingType: null });

    const visible = useMemo(() => {
        return documentTypes
            .filter((d) => d.title.toLowerCase().includes(docSearch.toLowerCase()))
            .filter((d) => !otherStagesDocs.has(String(d.id)) || selectedDocs.some((sd) => String(sd.type) === String(d.id)));
    }, [docSearch, documentTypes, otherStagesDocs, selectedDocs]);

    const orderByType = useMemo(() => {
        return new Map(selectedDocs.map((d, i) => [String(d.type), i + 1] as const));
    }, [selectedDocs]);

    return (
        <div className="space-y-4 pt-4 border-t border-border/40">
            <div className="flex items-center justify-between">
                <Label className="text-sm font-medium flex items-center gap-2">
                    Select Documents
                    <span className="text-[10px] text-primary/80 font-mono py-0.5 px-1.5 rounded-md bg-primary/10">
                        {selectedDocs.length} sel
                    </span>
                </Label>

                <div className="flex flex-wrap items-center gap-2 sm:gap-4">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7 text-[10px] px-2 rounded-lg"
                        disabled={selectedDocs.length < 2}
                        onClick={() => setSort({ open: true, list: [...selectedDocs], draggingType: null })}
                    >
                        Sort
                    </Button>

                    <Input
                        placeholder="Search docs..."
                        className="h-7 text-[11px] w-full sm:w-40 rounded-lg bg-card/30"
                        value={docSearch}
                        onChange={(e) => setDocSearch(e.target.value)}
                    />

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-6 text-[10px] px-2 text-muted-foreground hover:text-primary uppercase tracking-wider font-semibold"
                        onClick={() => {
                            const available = visible.filter((d) => !otherStagesDocs.has(String(d.id)));
                            const selectedInSearch = selectedDocs.filter((sd) =>
                                available.some((a) => String(a.id) === String(sd.type)),
                            );

                            if (selectedInSearch.length === available.length && available.length > 0) {
                                onUpdate(selectedDocs.filter((sd) => !available.some((a) => String(a.id) === String(sd.type))));

                                return;
                            }

                            const toAdd = available
                                .filter((a) => !selectedDocs.some((sd) => String(sd.type) === String(a.id)))
                                .map((a) => ({ type: String(a.id), min: 1 }));

                            onUpdate([...selectedDocs, ...toAdd]);
                        }}
                    >
                        {selectedDocs.length === documentTypes.filter((d) => !otherStagesDocs.has(String(d.id))).length &&
                        selectedDocs.length > 0
                            ? 'Deselect All'
                            : 'Select All'}
                    </Button>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-2 p-3 rounded-xl border border-border/50 bg-card/30 max-h-[260px] overflow-y-auto">
                {visible.length === 0 ? (
                    <div className="py-8 text-center text-xs text-muted-foreground">No documents found.</div>
                ) : (
                    visible.map((d) => {
                        const isSelected = selectedDocs.some((sd) => String(sd.type) === String(d.id));
                        const doc = selectedDocs.find((sd) => String(sd.type) === String(d.id));

                        return (
                            <div
                                key={d.id}
                                className={`flex flex-col p-2.5 rounded-lg border transition-all ${
                                    isSelected ? 'border-primary/50 bg-primary/5' : 'border-border/50 bg-card/30'
                                }`}
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <label className="flex items-center gap-2.5 text-sm cursor-pointer group flex-1">
                                        <input
                                            type="checkbox"
                                            className="rounded border-border/50 text-primary w-4 h-4 focus:ring-primary/20"
                                            checked={isSelected}
                                            onChange={(e) => {
                                                const next = e.target.checked;
                                                onUpdate(
                                                    next
                                                        ? [...selectedDocs, { type: String(d.id), min: 1 }]
                                                        : selectedDocs.filter((sd) => String(sd.type) !== String(d.id)),
                                                );
                                            }}
                                        />
                                        {isSelected && (
                                            <span className="text-[10px] font-bold tabular-nums text-primary bg-primary/10 border border-primary/20 rounded-md px-1.5 py-0.5">
                                                #{orderByType.get(String(d.id))}
                                            </span>
                                        )}
                                        <span className="group-hover:text-primary transition-colors font-medium">
                                            {d.title}
                                        </span>
                                    </label>

                                    {isSelected && (
                                        <div className="flex items-center gap-3 shrink-0">
                                            <div className="flex items-center gap-2">
                                                <span className="text-[10px] uppercase font-bold text-muted-foreground">
                                                    Min files
                                                </span>
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    className="h-7 w-16 text-[11px] px-2"
                                                    value={String(doc?.min ?? 1)}
                                                    onChange={(e) => {
                                                        const nextMin = Math.max(1, Number(e.target.value || 1));
                                                        onUpdate(
                                                            selectedDocs.map((sd) =>
                                                                String(sd.type) === String(d.id) ? { ...sd, min: nextMin } : sd,
                                                            ),
                                                        );
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {isSelected && (
                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-3 mt-3 border-t border-border/40">
                                        <label className="flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-background/30 px-3 py-2 cursor-pointer">
                                            <span className="text-xs font-medium text-foreground">Issue date</span>
                                            <Switch
                                                checked={!!doc?.ask_issue_date}
                                                onCheckedChange={(val) => {
                                                    onUpdate(
                                                        selectedDocs.map((sd) =>
                                                            String(sd.type) === String(d.id) ? { ...sd, ask_issue_date: val } : sd,
                                                        ),
                                                    );
                                                }}
                                                className="scale-75"
                                            />
                                        </label>
                                        <label className="flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-background/30 px-3 py-2 cursor-pointer">
                                            <span className="text-xs font-medium text-foreground">Expiry date</span>
                                            <Switch
                                                checked={!!doc?.ask_expiry_date}
                                                onCheckedChange={(val) => {
                                                    onUpdate(
                                                        selectedDocs.map((sd) =>
                                                            String(sd.type) === String(d.id) ? { ...sd, ask_expiry_date: val } : sd,
                                                        ),
                                                    );
                                                }}
                                                className="scale-75"
                                            />
                                        </label>
                                        <label className="flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-background/30 px-3 py-2 cursor-pointer">
                                            <span className="text-xs font-medium text-foreground">Doc number</span>
                                            <Switch
                                                checked={!!doc?.ask_document_number}
                                                onCheckedChange={(val) => {
                                                    onUpdate(
                                                        selectedDocs.map((sd) =>
                                                            String(sd.type) === String(d.id) ? { ...sd, ask_document_number: val } : sd,
                                                        ),
                                                    );
                                                }}
                                                className="scale-75"
                                            />
                                        </label>
                                    </div>
                                )}
                            </div>
                        );
                    })
                )}
            </div>

            <Dialog
                open={sort.open}
                onOpenChange={(open) => {
                    if (!open) {
                        setSort({ open: false, list: [], draggingType: null });
                    } else {
                        setSort((d) => ({ ...d, open: true }));
                    }
                }}
            >
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Sort documents</DialogTitle>
                        <DialogDescription>
                            Drag items to reorder, or use the arrows. This order will be saved in the template.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="max-h-[60vh] overflow-y-auto rounded-lg border border-border">
                        <div className="divide-y divide-border">
                            {sort.list.map((item, idx) => {
                                const dt = documentTypes.find((x) => String(x.id) === String(item.type));
                                const label = dt?.title ?? item.type;

                                return (
                                    <div
                                        key={String(item.type)}
                                        className="flex items-center justify-between gap-3 px-4 py-3 bg-background"
                                        draggable
                                        onDragStart={() => setSort((d) => ({ ...d, draggingType: String(item.type) }))}
                                        onDragOver={(e) => e.preventDefault()}
                                        onDrop={() => {
                                            setSort((d) => {
                                                const dragType = d.draggingType;

                                                if (!dragType || dragType === String(item.type)) {
                                                    return d;
                                                }

                                                const from = d.list.findIndex((p) => String(p.type) === dragType);
                                                const to = d.list.findIndex((p) => String(p.type) === String(item.type));

                                                if (from === -1 || to === -1) {
                                                    return { ...d, draggingType: null };
                                                }

                                                const next = [...d.list];
                                                const [moved] = next.splice(from, 1);
                                                next.splice(to, 0, moved);

                                                return { ...d, list: next, draggingType: null };
                                            });
                                        }}
                                    >
                                        <div className="flex items-center gap-3 min-w-0">
                                            <GripVertical className="h-4 w-4 text-muted-foreground/70 shrink-0" />
                                            <div className="text-[11px] font-bold tabular-nums text-muted-foreground bg-muted/40 border border-border rounded-md px-2 py-1">
                                                {idx + 1}
                                            </div>
                                            <div className="min-w-0">
                                                <div className="text-sm font-medium truncate">{label}</div>
                                                <div className="text-[10px] text-muted-foreground">
                                                    Min {item.min}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-1 shrink-0">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="h-8 w-8"
                                                disabled={idx === 0}
                                                onClick={() => {
                                                    setSort((d) => {
                                                        if (idx === 0) {
                                                            return d;
                                                        }

                                                        const next = [...d.list];
                                                        const tmp = next[idx - 1];
                                                        next[idx - 1] = next[idx];
                                                        next[idx] = tmp;

                                                        return { ...d, list: next };
                                                    });
                                                }}
                                            >
                                                <ArrowUp className="h-4 w-4" />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="h-8 w-8"
                                                disabled={idx === sort.list.length - 1}
                                                onClick={() => {
                                                    setSort((d) => {
                                                        if (idx === d.list.length - 1) {
                                                            return d;
                                                        }

                                                        const next = [...d.list];
                                                        const tmp = next[idx + 1];
                                                        next[idx + 1] = next[idx];
                                                        next[idx] = tmp;

                                                        return { ...d, list: next };
                                                    });
                                                }}
                                            >
                                                <ArrowDown className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}

                            {sort.list.length === 0 && (
                                <div className="px-4 py-10 text-center text-sm text-muted-foreground">
                                    No selected documents to sort.
                                </div>
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => setSort({ open: false, list: [], draggingType: null })}>
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                onUpdate(sort.list);
                                setSort({ open: false, list: [], draggingType: null });
                                toast.success('Documents order updated.');
                            }}
                        >
                            Save order
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

