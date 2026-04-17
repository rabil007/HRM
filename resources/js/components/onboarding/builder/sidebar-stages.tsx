import { GripVertical } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { BuilderState, StageBuilder } from '@/pages/onboarding/template-form';

export function SidebarStages({
    builder,
    setBuilder,
    activeStageId,
    setActiveStageId,
    reorderStages,
    generateId,
}: {
    builder: BuilderState;
    setBuilder: (next: BuilderState | ((prev: BuilderState) => BuilderState)) => void;
    activeStageId: string | null;
    setActiveStageId: (id: string | null) => void;
    reorderStages: (startIndex: number, endIndex: number) => void;
    generateId: () => string;
}) {
    return (
        <div className="glass-card p-0 flex flex-col lg:col-span-3 lg:sticky lg:top-8 overflow-hidden border-border/50">
            <div className="p-4 border-b border-border/50 flex items-center justify-between bg-card/40">
                <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground/70">Workflow Stages</h3>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        const newStage: StageBuilder = {
                            id: generateId(),
                            key: `stage_${builder.stages.length + 1}`,
                            label: `Stage ${builder.stages.length + 1}`,
                            employee_fields: [],
                            bank_account_fields: [],
                            contract_fields: [],
                            documents: [],
                        };

                        setBuilder((prev) => ({ stages: [...prev.stages, newStage] }));
                        setActiveStageId(newStage.id);
                    }}
                >
                    Add Stage
                </Button>
            </div>

            <div className="flex flex-col p-2 space-y-1 lg:max-h-[600px] lg:overflow-y-auto">
                {builder.stages.map((s, idx) => {
                    const isActive = s.id === activeStageId;

                    return (
                        <button
                            key={s.id}
                            type="button"
                            draggable
                            onDragStart={(e) => {
                                e.dataTransfer.effectAllowed = 'move';
                                e.dataTransfer.setData('text/plain', String(idx));
                            }}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={(e) => {
                                e.preventDefault();
                                const from = Number(e.dataTransfer.getData('text/plain'));

                                if (!Number.isNaN(from) && from !== idx) {
                                    reorderStages(from, idx);
                                }
                            }}
                            onClick={() => setActiveStageId(s.id)}
                            className={`flex flex-col text-left p-3 rounded-xl border transition-all cursor-move ${
                                isActive
                                    ? 'bg-primary/20 border-primary/50 shadow-sm'
                                    : 'border-transparent hover:bg-muted/40'
                            }`}
                        >
                            <div className="flex items-center justify-between w-full">
                                <span className={`font-semibold text-sm flex items-center gap-2 ${isActive ? 'text-primary' : 'text-foreground'}`}>
                                    <GripVertical className="w-4 h-4 opacity-40 cursor-grab active:cursor-grabbing" />
                                    {s.label || s.key}
                                </span>
                            </div>
                            <div className="text-[10px] uppercase font-bold text-muted-foreground/70 mt-2 grid grid-cols-4 gap-x-3 gap-y-1 pl-6">
                                <span>{s.employee_fields?.length ?? 0} Profile</span>
                                <span>{s.bank_account_fields?.length ?? 0} Bank</span>
                                <span>{s.contract_fields?.length ?? 0} Contract</span>
                                <span>{s.documents?.length ?? 0} Docs</span>
                            </div>
                        </button>
                    );
                })}

                {builder.stages.length === 0 && (
                    <div className="p-6 text-center text-sm text-muted-foreground">No stages defined.</div>
                )}
            </div>
        </div>
    );
}

