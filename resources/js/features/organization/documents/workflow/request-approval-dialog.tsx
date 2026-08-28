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
    WorkflowStageInput,
} from '@/features/organization/documents/workflow/types';
import { WorkflowAssigneeMultiSelect } from '@/features/organization/documents/workflow/workflow-assignee-multi-select';

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
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    documentId: number;
    assigneeOptions: WorkflowAssigneeOption[];
}) {
    const [stages, setStages] = useState<StageForm[]>([
        createStage('review'),
        createStage('approve'),
    ]);

    const form = useForm<{ stages: WorkflowStageInput[] }>({
        stages: [],
    });

    const assigneeItems = useMemo(() => assigneeOptions, [assigneeOptions]);

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

    function submit() {
        const payload = stages.map(
            ({ action, completion_rule, assignee_user_ids }) => ({
                action,
                completion_rule,
                assignee_user_ids,
            }),
        );

        form.transform(() => ({ stages: payload }));
        form.post(
            CreateDocumentWorkflowRequestController.url({
                employee: employeeId,
                document: documentId,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    form.reset();
                },
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Request approval</DialogTitle>
                    <DialogDescription>
                        Configure sequential review and approval stages for this
                        exact generated document version.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
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
                                        onClick={() => removeStage(index)}
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
                                        disabled={index === stages.length - 1}
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
                                                completion_rule: value as
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
                                options={assigneeOptionsForStage(stage.action)}
                                value={stage.assignee_user_ids}
                                onChange={(assignee_user_ids) =>
                                    updateStage(index, { assignee_user_ids })
                                }
                                error={
                                    form.errors[
                                        `stages.${index}.assignee_user_ids`
                                    ]
                                }
                            />
                        </div>
                    ))}

                    <Button type="button" variant="outline" onClick={addStage}>
                        <Plus className="mr-2 h-4 w-4" />
                        Add review stage
                    </Button>

                    {form.errors.stages ? (
                        <p className="text-sm text-destructive">
                            {form.errors.stages}
                        </p>
                    ) : null}
                    {(form.errors as Record<string, string>).workflow ? (
                        <p className="text-sm text-destructive">
                            {(form.errors as Record<string, string>).workflow}
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
                        onClick={submit}
                        disabled={form.processing}
                    >
                        Create request
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
