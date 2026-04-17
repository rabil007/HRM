import { ArrowDown, ArrowUp, GripVertical } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type SortDialogKind = 'employee' | 'contract' | 'bank' | null;

export type SortDialogItem = { key: string; required: boolean };

export type SortDialogState = {
    open: boolean;
    kind: SortDialogKind;
    list: SortDialogItem[];
    draggingKey: string | null;
};

export function SortDialog({
    state,
    setState,
    getLabel,
    onSave,
}: {
    state: SortDialogState;
    setState: (next: SortDialogState | ((prev: SortDialogState) => SortDialogState)) => void;
    getLabel: (key: string) => string;
    onSave: (kind: Exclude<SortDialogKind, null>, list: SortDialogItem[]) => void;
}) {
    return (
        <Dialog
            open={state.open}
            onOpenChange={(open) => {
                if (!open) {
                    setState({ open: false, kind: null, list: [], draggingKey: null });
                } else {
                    setState((d) => ({ ...d, open: true }));
                }
            }}
        >
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {state.kind === 'contract'
                            ? 'Sort contract fields'
                            : state.kind === 'bank'
                              ? 'Sort bank fields'
                              : 'Sort employee fields'}
                    </DialogTitle>
                    <DialogDescription>
                        Drag items to reorder, or use the arrows. This order will be saved in the template.
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[60vh] overflow-y-auto rounded-lg border border-border">
                    <div className="divide-y divide-border">
                        {state.list.map((item, idx) => {
                            const label = getLabel(item.key);

                            return (
                                <div
                                    key={item.key}
                                    className="flex items-center justify-between gap-3 px-4 py-3 bg-background"
                                    draggable
                                    onDragStart={() => setState((d) => ({ ...d, draggingKey: item.key }))}
                                    onDragOver={(e) => {
                                        e.preventDefault();
                                    }}
                                    onDrop={() => {
                                        setState((d) => {
                                            const dragKey = d.draggingKey;

                                            if (!dragKey || dragKey === item.key) {
                                                return d;
                                            }

                                            const from = d.list.findIndex((p) => p.key === dragKey);
                                            const to = d.list.findIndex((p) => p.key === item.key);

                                            if (from === -1 || to === -1) {
                                                return { ...d, draggingKey: null };
                                            }

                                            const next = [...d.list];
                                            const [moved] = next.splice(from, 1);
                                            next.splice(to, 0, moved);

                                            return { ...d, list: next, draggingKey: null };
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
                                                {item.required ? 'Required' : 'Optional'}
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
                                                setState((d) => {
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
                                            disabled={idx === state.list.length - 1}
                                            onClick={() => {
                                                setState((d) => {
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

                        {state.list.length === 0 && (
                            <div className="px-4 py-10 text-center text-sm text-muted-foreground">
                                No selected fields to sort.
                            </div>
                        )}
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={() => setState({ open: false, kind: null, list: [], draggingKey: null })}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={() => {
                            if (!state.kind) {
                                setState({ open: false, kind: null, list: [], draggingKey: null });

                                return;
                            }

                            onSave(state.kind, state.list);
                        }}
                    >
                        Save order
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

