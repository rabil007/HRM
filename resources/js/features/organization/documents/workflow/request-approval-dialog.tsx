import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import CreateDocumentWorkflowRequestController from '@/actions/App/Http/Controllers/Organization/Documents/CreateDocumentWorkflowRequestController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type {
    WorkflowAssigneeOption,
    WorkflowPresetSummary,
    WorkflowStageInput,
} from '@/features/organization/documents/workflow/types';
import { WorkflowAssigneeMultiSelect } from '@/features/organization/documents/workflow/workflow-assignee-multi-select';
import { cn } from '@/lib/utils';

type StageForm = WorkflowStageInput & { key: string };

function createStage(action: 'review' | 'approve' = 'review'): StageForm {
    return {
        key: crypto.randomUUID(),
        action,
        completion_rule: 'all',
        assignee_user_ids: [],
    };
}

export function RequestApprovalDialog({
    open,
    onOpenChange,
    employeeId,
    documentId,
    assigneeOptions,
    presets = [],
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    documentId: number;
    assigneeOptions: WorkflowAssigneeOption[];
    presets?: WorkflowPresetSummary[];
}) {
    const [mode, setMode] = useState<'manual' | number>('manual');
    const [stages, setStages] = useState<StageForm[]>([
        createStage('review'),
        createStage('approve'),
    ]);

    const manualForm = useForm<{ stages: WorkflowStageInput[] }>({
        stages: [],
    });

    const presetForm = useForm<{ workflow_preset_id: number | null }>({
        workflow_preset_id: null,
    });

    const assigneeItems = useMemo(() => assigneeOptions, [assigneeOptions]);

    const selectedPreset = useMemo(
        () =>
            typeof mode === 'number'
                ? presets.find((preset) => preset.id === mode) ?? null
                : null,
        [mode, presets],
    );

    function assigneeOptionsForStage(action: 'review' | 'approve') {
        return assigneeItems.filter((option) =>
            action === 'review' ? option.can_review : option.can_approve,
        );
    }

    function updateStage(index: number, patch: Partial<StageForm>) {
        setStages((current) =>
            current.map((stage, stageIndex) =>
                stageIndex === index ? { ...stage, ...patch } : stage,
            ),
        );
    }

    function addStage() {
        setStages((current) => {
            const next = [...current];
            next.splice(Math.max(0, next.length - 1), 0, createStage('review'));

            return next;
        });
    }

    function removeStage(index: number) {
        setStages((current) => {
            if (current.length <= 1) {
                return current;
            }

            return current.filter((_, stageIndex) => stageIndex !== index);
        });
    }

    function submitManual() {
        const payload = stages.map(
            ({ action, completion_rule, assignee_user_ids }) => ({
                action,
                completion_rule,
                assignee_user_ids,
            }),
        );

        manualForm.transform(() => ({ stages: payload }));
        manualForm.post(
            CreateDocumentWorkflowRequestController.url({
                employee: employeeId,
                document: documentId,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    manualForm.reset();
                },
            },
        );
    }

    function submitPreset() {
        if (selectedPreset === null) {
            return;
        }

        presetForm.transform(() => ({
            workflow_preset_id: selectedPreset.id,
        }));
        presetForm.post(
            CreateDocumentWorkflowRequestController.url({
                employee: employeeId,
                document: documentId,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    presetForm.reset();
                },
            },
        );
    }

    const formErrors = (mode === 'manual' ? manualForm.errors : presetForm.errors) as Record<
        string,
        string
    >;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Request approval</DialogTitle>
                    <DialogDescription>
                        Choose a reusable workflow preset or configure manual
                        review and approval stages for this exact generated
                        document version.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    <div className="space-y-2">
                        <Label>Workflow</Label>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant={mode === 'manual' ? 'default' : 'outline'}
                                onClick={() => setMode('manual')}
                            >
                                Manual
                            </Button>
                            {presets.map((preset) => (
                                <Button
                                    key={preset.id}
                                    type="button"
                                    size="sm"
                                    variant={
                                        mode === preset.id
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setMode(preset.id)}
                                >
                                    {preset.name}
                                </Button>
                            ))}
                        </div>
                    </div>

                    {mode === 'manual' ? (
                        <>
                            {stages.map((stage, index) => (
                                <div
                                    key={stage.key}
                                    className="space-y-3 rounded-xl border border-border/70 p-4"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-sm font-semibold">
                                            Stage {index + 1}
                                        </p>
                                        {stages.length > 1 &&
                                        index < stages.length - 1 ? (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                onClick={() =>
                                                    removeStage(index)
                                                }
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        ) : null}
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Action</Label>
                                            <AppSelect
                                                value={stage.action}
                                                onValueChange={(value) =>
                                                    updateStage(index, {
                                                        action: value as
                                                            | 'review'
                                                            | 'approve',
                                                    })
                                                }
                                                disabled={
                                                    index === stages.length - 1
                                                }
                                            >
                                                <AppSelectItem value="review">
                                                    Review
                                                </AppSelectItem>
                                                <AppSelectItem value="approve">
                                                    Approve
                                                </AppSelectItem>
                                            </AppSelect>
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Completion rule</Label>
                                            <AppSelect
                                                value={stage.completion_rule}
                                                onValueChange={(value) =>
                                                    updateStage(index, {
                                                        completion_rule:
                                                            value as
                                                                | 'all'
                                                                | 'any',
                                                    })
                                                }
                                            >
                                                <AppSelectItem value="all">
                                                    All
                                                </AppSelectItem>
                                                <AppSelectItem value="any">
                                                    Any
                                                </AppSelectItem>
                                            </AppSelect>
                                        </div>
                                    </div>

                                    <WorkflowAssigneeMultiSelect
                                        label="Assignees"
                                        options={assigneeOptionsForStage(
                                            stage.action,
                                        )}
                                        value={stage.assignee_user_ids}
                                        onChange={(assignee_user_ids) =>
                                            updateStage(index, {
                                                assignee_user_ids,
                                            })
                                        }
                                        error={
                                            manualForm.errors[
                                                `stages.${index}.assignee_user_ids`
                                            ]
                                        }
                                    />
                                </div>
                            ))}

                            <Button
                                type="button"
                                variant="outline"
                                onClick={addStage}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Add review stage
                            </Button>
                        </>
                    ) : selectedPreset ? (
                        <div className="space-y-3 rounded-xl border border-border/70 p-4">
                            <div>
                                <p className="text-sm font-semibold">
                                    {selectedPreset.name}
                                </p>
                                {selectedPreset.description ? (
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {selectedPreset.description}
                                    </p>
                                ) : null}
                            </div>
                            <div className="space-y-3">
                                {(selectedPreset.stages ?? []).map((stage) => (
                                    <div
                                        key={stage.sequence}
                                        className="rounded-lg bg-muted/40 p-3"
                                    >
                                        <p className="text-sm font-medium">
                                            {stage.sequence}. {stage.action_label}{' '}
                                            — {stage.completion_rule_label}
                                        </p>
                                        <ul className="mt-2 space-y-1 text-sm text-muted-foreground">
                                            {stage.targets.map((target) => (
                                                <li key={target.label}>
                                                    {target.label}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : null}

                    {formErrors.stages ? (
                        <p className="text-sm text-destructive">
                            {formErrors.stages}
                        </p>
                    ) : null}
                    {formErrors.workflow_preset_id ? (
                        <p className="text-sm text-destructive">
                            {formErrors.workflow_preset_id}
                        </p>
                    ) : null}
                    {formErrors.workflow ? (
                        <p className="text-sm text-destructive">
                            {formErrors.workflow}
                        </p>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={
                            mode === 'manual' ? submitManual : submitPreset
                        }
                        disabled={
                            mode === 'manual'
                                ? manualForm.processing
                                : presetForm.processing
                        }
                        className={cn(mode !== 'manual' && !selectedPreset && 'hidden')}
                    >
                        Create request
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
