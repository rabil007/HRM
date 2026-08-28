import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    WorkflowPresetFormOptions,
    WorkflowPresetStageInput,
    WorkflowPresetSummary,
    WorkflowPresetTargetInput,
} from '@/features/organization/documents/workflow/types';
import {
    store as storePreset,
    update as updatePreset,
} from '@/routes/organization/documents/workflow-presets';

type StageForm = {
    key: string;
    action: 'review' | 'approve';
    completion_rule: 'all' | 'any';
    targets: TargetForm[];
};
type TargetForm = WorkflowPresetTargetInput & { key: string };

function createTarget(): TargetForm {
    return {
        key: crypto.randomUUID(),
        target_type: 'department_manager',
        target_user_id: null,
        target_role_id: null,
    };
}

function createStage(action: 'review' | 'approve' = 'review'): StageForm {
    return {
        key: crypto.randomUUID(),
        action,
        completion_rule: 'all',
        targets: [createTarget()],
    };
}

function stagesFromPreset(preset: WorkflowPresetSummary | null): StageForm[] {
    if (!preset?.stages?.length) {
        return [createStage('review'), createStage('approve')];
    }

    return preset.stages.map((stage) => ({
        key: crypto.randomUUID(),
        action: stage.action as 'review' | 'approve',
        completion_rule: stage.completion_rule as 'all' | 'any',
        targets: stage.targets.map((target) => ({
            key: crypto.randomUUID(),
            target_type: target.target_type as TargetForm['target_type'],
            target_user_id: target.target_user_id,
            target_role_id: target.target_role_id,
        })),
    }));
}

export function WorkflowPresetFormDialog({
    open,
    onOpenChange,
    preset,
    formOptions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    preset: WorkflowPresetSummary | null;
    formOptions: WorkflowPresetFormOptions;
}) {
    if (!open) {
        return null;
    }

    return (
        <WorkflowPresetFormDialogBody
            key={preset?.id ?? 'new'}
            open={open}
            onOpenChange={onOpenChange}
            preset={preset}
            formOptions={formOptions}
        />
    );
}

function WorkflowPresetFormDialogBody({
    open,
    onOpenChange,
    preset,
    formOptions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    preset: WorkflowPresetSummary | null;
    formOptions: WorkflowPresetFormOptions;
}) {
    const [stages, setStages] = useState<StageForm[]>(() =>
        stagesFromPreset(preset),
    );

    const form = useForm<{
        name: string;
        description: string;
        stages: WorkflowPresetStageInput[];
    }>({
        name: preset?.name ?? '',
        description: preset?.description ?? '',
        stages: [],
    });

    const targetTypeMap = useMemo(
        () =>
            Object.fromEntries(
                formOptions.target_types.map((type) => [type.value, type]),
            ),
        [formOptions.target_types],
    );

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

    function updateTarget(
        stageIndex: number,
        targetIndex: number,
        patch: Partial<TargetForm>,
    ) {
        setStages((current) =>
            current.map((stage, currentStageIndex) => {
                if (currentStageIndex !== stageIndex) {
                    return stage;
                }

                return {
                    ...stage,
                    targets: stage.targets.map((target, currentTargetIndex) =>
                        currentTargetIndex === targetIndex
                            ? { ...target, ...patch }
                            : target,
                    ),
                };
            }),
        );
    }

    function addTarget(stageIndex: number) {
        setStages((current) =>
            current.map((stage, index) =>
                index === stageIndex
                    ? { ...stage, targets: [...stage.targets, createTarget()] }
                    : stage,
            ),
        );
    }

    function removeTarget(stageIndex: number, targetIndex: number) {
        setStages((current) =>
            current.map((stage, index) => {
                if (index !== stageIndex || stage.targets.length <= 1) {
                    return stage;
                }

                return {
                    ...stage,
                    targets: stage.targets.filter(
                        (_, currentTargetIndex) =>
                            currentTargetIndex !== targetIndex,
                    ),
                };
            }),
        );
    }

    function submit() {
        const payloadStages = stages.map(({ action, completion_rule, targets }) => ({
            action,
            completion_rule,
            targets: targets.map(
                ({ target_type, target_user_id, target_role_id }) => ({
                    target_type,
                    target_user_id,
                    target_role_id,
                }),
            ),
        }));

        form.transform((data) => ({
            ...data,
            stages: payloadStages,
        }));

        if (preset) {
            form.put(updatePreset.url(preset.id), {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });

            return;
        }

        form.post(storePreset.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        {preset ? 'Edit workflow preset' : 'New workflow preset'}
                    </DialogTitle>
                    <DialogDescription>
                        Configure sequential stages and routing targets. Dynamic
                        manager and role targets resolve when a request is
                        created.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="preset-name">Name</Label>
                            <Input
                                id="preset-name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.name}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="preset-description">
                                Description
                            </Label>
                            <Textarea
                                id="preset-description"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                                rows={2}
                            />
                        </div>
                    </div>

                    {stages.map((stage, stageIndex) => (
                        <div
                            key={stage.key}
                            className="space-y-3 rounded-xl border border-border/70 p-4"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-sm font-semibold">
                                    Stage {stageIndex + 1}
                                </p>
                                {stages.length > 1 &&
                                stageIndex < stages.length - 1 ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => removeStage(stageIndex)}
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
                                            updateStage(stageIndex, {
                                                action: value as
                                                    | 'review'
                                                    | 'approve',
                                            })
                                        }
                                        disabled={
                                            stageIndex === stages.length - 1
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
                                            updateStage(stageIndex, {
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

                            <div className="space-y-3">
                                {stage.targets.map((target, targetIndex) => {
                                    const targetType =
                                        targetTypeMap[target.target_type];

                                    return (
                                        <div
                                            key={target.key}
                                            className="grid gap-3 rounded-lg bg-muted/30 p-3 sm:grid-cols-[1fr_1fr_auto]"
                                        >
                                            <div className="space-y-2">
                                                <Label>Target type</Label>
                                                <AppSelect
                                                    value={target.target_type}
                                                    onValueChange={(value) =>
                                                        updateTarget(
                                                            stageIndex,
                                                            targetIndex,
                                                            {
                                                                target_type:
                                                                    value as TargetForm['target_type'],
                                                                target_user_id:
                                                                    null,
                                                                target_role_id:
                                                                    null,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {formOptions.target_types.map(
                                                        (type) => (
                                                            <AppSelectItem
                                                                key={type.value}
                                                                value={
                                                                    type.value
                                                                }
                                                            >
                                                                {type.label}
                                                            </AppSelectItem>
                                                        ),
                                                    )}
                                                </AppSelect>
                                            </div>
                                            {targetType?.requires_user ? (
                                                <div className="space-y-2">
                                                    <Label>User</Label>
                                                    <AppSelect
                                                        value={
                                                            target.target_user_id
                                                                ? String(
                                                                      target.target_user_id,
                                                                  )
                                                                : ''
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            updateTarget(
                                                                stageIndex,
                                                                targetIndex,
                                                                {
                                                                    target_user_id:
                                                                        Number(
                                                                            value,
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {formOptions.users.map(
                                                            (user) => (
                                                                <AppSelectItem
                                                                    key={
                                                                        user.id
                                                                    }
                                                                    value={String(
                                                                        user.id,
                                                                    )}
                                                                >
                                                                    {user.name}
                                                                </AppSelectItem>
                                                            ),
                                                        )}
                                                    </AppSelect>
                                                </div>
                                            ) : null}
                                            {targetType?.requires_role ? (
                                                <div className="space-y-2">
                                                    <Label>Role</Label>
                                                    <AppSelect
                                                        value={
                                                            target.target_role_id
                                                                ? String(
                                                                      target.target_role_id,
                                                                  )
                                                                : ''
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            updateTarget(
                                                                stageIndex,
                                                                targetIndex,
                                                                {
                                                                    target_role_id:
                                                                        Number(
                                                                            value,
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {formOptions.roles.map(
                                                            (role) => (
                                                                <AppSelectItem
                                                                    key={
                                                                        role.id
                                                                    }
                                                                    value={String(
                                                                        role.id,
                                                                    )}
                                                                >
                                                                    {role.name}
                                                                </AppSelectItem>
                                                            ),
                                                        )}
                                                    </AppSelect>
                                                </div>
                                            ) : null}
                                            <div className="flex items-end justify-end">
                                                {stage.targets.length > 1 ? (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() =>
                                                            removeTarget(
                                                                stageIndex,
                                                                targetIndex,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </div>
                                    );
                                })}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => addTarget(stageIndex)}
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add target
                                </Button>
                            </div>
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
                        {preset ? 'Save preset' : 'Create preset'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
