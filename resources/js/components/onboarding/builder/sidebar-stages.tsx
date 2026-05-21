import { GripVertical, Layers, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { BuilderState, StageBuilder } from '@/pages/onboarding/template-form';

export function SidebarStages({
    builder,
    setBuilder,
    activeStageId,
    setActiveStageId,
    reorderStages,
    generateId,
    onStageActivated,
}: {
    builder: BuilderState;
    setBuilder: (next: BuilderState | ((prev: BuilderState) => BuilderState)) => void;
    activeStageId: string | null;
    setActiveStageId: (id: string | null) => void;
    reorderStages: (startIndex: number, endIndex: number) => void;
    generateId: () => string;
    onStageActivated?: () => void;
}) {
    const totalSelections = (
        ef: typeof builder.stages[0]['employee_fields'],
        bf: typeof builder.stages[0]['bank_account_fields'],
        cf: typeof builder.stages[0]['contract_fields'],
        sf: typeof builder.stages[0]['sea_service_fields'],
        vf: typeof builder.stages[0]['vaccination_fields'],
        tf: typeof builder.stages[0]['training_fields'],
        docs: typeof builder.stages[0]['documents'],
    ) =>
        ef.length +
        bf.length +
        cf.length +
        sf.length +
        vf.length +
        tf.length +
        docs.filter((d) => String(d?.type ?? '').trim() !== '').length;

    return (
        <div className="flex flex-col gap-3 lg:col-span-3 lg:sticky lg:top-4">
            <div className="rounded-2xl border border-border bg-card shadow-sm lg:sticky lg:top-4 lg:overflow-hidden">
                <div className="flex flex-col gap-3 border-b border-border bg-muted/30 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-start gap-2">
                        <div className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Layers className="size-4" />
                        </div>
                        <div className="min-w-0">
                            <h3 className="text-sm font-semibold leading-none text-foreground">Workflow steps</h3>
                            <p className="mt-1 text-xs leading-snug text-muted-foreground">
                                Order matches the hire wizard. Drag a row by the handle to reorder.
                            </p>
                        </div>
                    </div>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        className="h-9 shrink-0 gap-1.5"
                        onClick={() => {
                            const newStage: StageBuilder = {
                                id: generateId(),
                                key: `stage_${builder.stages.length + 1}`,
                                label: `Step ${builder.stages.length + 1}`,
                                employee_fields: [],
                                bank_account_fields: [],
                                contract_fields: [],
                                sea_service_fields: [],
                                vaccination_fields: [],
                                training_fields: [],
                                documents: [],
                            };

                            setBuilder((prev) => ({ stages: [...prev.stages, newStage] }));
                            setActiveStageId(newStage.id);
                            onStageActivated?.();
                        }}
                    >
                        <Plus className="size-4" />
                        Add step
                    </Button>
                </div>

                <div className="flex max-h-[min(70vh,640px)] flex-col gap-1.5 overflow-y-auto p-2">
                    {builder.stages.map((st, idx) => {
                        const isActive = st.id === activeStageId;

                        return (
                            <button
                                key={st.id}
                                type="button"
                                draggable
                                onDragStart={(e) => {
                                    e.dataTransfer.effectAllowed = 'move';
                                    e.dataTransfer.setData('text/plain', String(idx));
                                }}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    e.dataTransfer.dropEffect = 'move';
                                }}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    const from = Number(e.dataTransfer.getData('text/plain'));

                                    if (!Number.isNaN(from) && from !== idx) {
                                        reorderStages(from, idx);
                                    }
                                }}
                                onClick={() => {
                                    setActiveStageId(st.id);

                                    if (activeStageId !== st.id) {
                                        onStageActivated?.();
                                    }
                                }}
                                title="Drag vertically to reorder, or choose to configure"
                                className={`flex w-full cursor-grab touch-manipulation flex-col rounded-xl border-2 px-3 py-2.5 text-left transition-colors active:cursor-grabbing ${
                                    isActive
                                        ? 'border-primary bg-primary/10 shadow-sm'
                                        : 'border-transparent bg-muted/20 hover:border-border hover:bg-muted/50'
                                }`}
                            >
                                <div className="flex items-center gap-2">
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-bold tabular-nums text-muted-foreground">
                                        {idx + 1}
                                    </span>
                                    <GripVertical className="size-4 shrink-0 opacity-40" aria-hidden />
                                    <div className="min-w-0 flex-1">
                                        <span
                                            className={`block truncate font-medium ${isActive ? 'text-primary' : 'text-foreground'}`}
                                        >
                                            {st.label || st.key}
                                        </span>
                                        <span className="text-[11px] text-muted-foreground">
                                            {totalSelections(
                                                st.employee_fields,
                                                st.bank_account_fields,
                                                st.contract_fields,
                                                st.sea_service_fields,
                                                st.vaccination_fields,
                                                st.training_fields,
                                                st.documents,
                                            )}{' '}
                                            items
                                        </span>
                                    </div>
                                </div>
                                <div className="mt-2 ml-14 grid grid-cols-3 gap-x-2 gap-y-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                                    <span>E {st.employee_fields?.length ?? 0}</span>
                                    <span>B {st.bank_account_fields?.length ?? 0}</span>
                                    <span>C {st.contract_fields?.length ?? 0}</span>
                                    <span>D{' '}
                                        {st.documents?.filter((d) => String(d?.type ?? '').trim()).length ??
                                            0}
                                    </span>
                                    <span>S {st.sea_service_fields?.length ?? 0}</span>
                                    <span>V {st.vaccination_fields?.length ?? 0}</span>
                                    <span>T {st.training_fields?.length ?? 0}</span>
                                </div>
                            </button>
                        );
                    })}

                    {builder.stages.length === 0 ? (
                        <div className="px-4 py-10 text-center text-sm text-muted-foreground">
                            No steps yet. Add one to start building onboarding.
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
