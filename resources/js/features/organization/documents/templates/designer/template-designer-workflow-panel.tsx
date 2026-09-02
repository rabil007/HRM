import {
    Root as RadioGroup,
    Item as RadioItem,
} from '@radix-ui/react-radio-group';
import { AlertTriangle, Plus } from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    executionOrderSteps,
    signingStepPlacementStatuses,
} from '../lib/template-workflow';
import type {
    DesignerSigningPreset,
    DesignerWorkflowPreset,
    TemplateAutomationMode,
} from '../types';

type Props = {
    readOnly: boolean;
    workflowMode: TemplateAutomationMode;
    workflowPresetId: number | null;
    signingMode: TemplateAutomationMode;
    signingPresetId: number | null;
    workflowPresets: DesignerWorkflowPreset[];
    signingPresets: DesignerSigningPreset[];
    placedSlotKeys: string[];
    slotPages: Record<string, number>;
    canCreateWorkflowPresets: boolean;
    canCreateSigningPresets: boolean;
    onWorkflowModeChange: (mode: TemplateAutomationMode) => void;
    onWorkflowPresetChange: (id: number | null) => void;
    onSigningModeChange: (mode: TemplateAutomationMode) => void;
    onSigningPresetChange: (id: number | null) => void;
    onCreateWorkflowPreset: () => void;
    onCreateSigningPreset: () => void;
    onPlaceSlot: (slotKey: string) => void;
    onRemoveSignaturePlacements: () => void;
    isLegacy: boolean;
};

export function TemplateDesignerWorkflowPanel({
    readOnly,
    workflowMode,
    workflowPresetId,
    signingMode,
    signingPresetId,
    workflowPresets,
    signingPresets,
    placedSlotKeys,
    slotPages,
    canCreateWorkflowPresets,
    canCreateSigningPresets,
    onWorkflowModeChange,
    onWorkflowPresetChange,
    onSigningModeChange,
    onSigningPresetChange,
    onCreateWorkflowPreset,
    onCreateSigningPreset,
    onPlaceSlot,
    onRemoveSignaturePlacements,
    isLegacy,
}: Props) {
    const selectedWorkflow = workflowPresets.find(
        (preset) => preset.id === workflowPresetId,
    );
    const selectedSigning = signingPresets.find(
        (preset) => preset.id === signingPresetId,
    );
    const signingStatuses = selectedSigning
        ? signingStepPlacementStatuses(
              selectedSigning.steps,
              placedSlotKeys,
              slotPages,
          )
        : [];
    const order = executionOrderSteps(
        workflowMode === 'preset'
            ? 'preset'
            : workflowMode === 'none'
              ? 'none'
              : null,
        signingMode === 'preset'
            ? 'preset'
            : signingMode === 'none'
              ? 'none'
              : null,
    );

    return (
        <div className="space-y-5 text-xs">
            {isLegacy ? (
                <p className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2 text-[11px] text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-100">
                    Legacy version. No explicit workflow decision was recorded.
                </p>
            ) : null}

            <section className="space-y-2">
                <p className="text-[11px] font-semibold tracking-wide text-foreground uppercase">
                    Review & Approval
                </p>
                {workflowMode == null && !readOnly ? (
                    <p className="flex items-center gap-1 text-[11px] text-amber-700 dark:text-amber-400">
                        <AlertTriangle className="size-3" />
                        Decision required
                    </p>
                ) : null}
                <RadioGroup
                    value={workflowMode ?? ''}
                    onValueChange={(value) =>
                        onWorkflowModeChange(value as 'none' | 'preset')
                    }
                    disabled={readOnly}
                    className="grid gap-2"
                >
                    <DecisionOption
                        value="none"
                        selected={workflowMode === 'none'}
                        disabled={readOnly}
                        title="No review required"
                    />
                    <DecisionOption
                        value="preset"
                        selected={workflowMode === 'preset'}
                        disabled={readOnly}
                        title="Use approval flow"
                    />
                </RadioGroup>
                {workflowMode === 'preset' ? (
                    <div className="space-y-2">
                        <AppSelect
                            value={
                                workflowPresetId !== null
                                    ? String(workflowPresetId)
                                    : ''
                            }
                            onValueChange={(value) =>
                                onWorkflowPresetChange(Number(value))
                            }
                            disabled={readOnly}
                            placeholder="Approval flow"
                        >
                            {workflowPresets.map((preset) => (
                                <AppSelectItem
                                    key={preset.id}
                                    value={String(preset.id)}
                                >
                                    {preset.name}
                                    {!preset.is_active ? ' (inactive)' : ''}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {canCreateWorkflowPresets && !readOnly ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 px-2 text-[11px]"
                                onClick={onCreateWorkflowPreset}
                            >
                                <Plus className="mr-1 size-3" />
                                Create approval flow
                            </Button>
                        ) : null}
                        {selectedWorkflow ? (
                            <ol className="space-y-1 text-[11px] text-muted-foreground">
                                {selectedWorkflow.stages.map((stage, index) => (
                                    <li key={stage.sequence}>
                                        {stage.action_label}
                                        {index <
                                        selectedWorkflow.stages.length - 1
                                            ? ' ↓'
                                            : ''}
                                    </li>
                                ))}
                            </ol>
                        ) : null}
                    </div>
                ) : null}
            </section>

            <section className="space-y-2">
                <p className="text-[11px] font-semibold tracking-wide text-foreground uppercase">
                    Signing
                </p>
                {signingMode == null && !readOnly ? (
                    <p className="flex items-center gap-1 text-[11px] text-amber-700 dark:text-amber-400">
                        <AlertTriangle className="size-3" />
                        Decision required
                    </p>
                ) : null}
                <RadioGroup
                    value={signingMode ?? ''}
                    onValueChange={(value) =>
                        onSigningModeChange(value as 'none' | 'preset')
                    }
                    disabled={readOnly}
                    className="grid gap-2"
                >
                    <DecisionOption
                        value="none"
                        selected={signingMode === 'none'}
                        disabled={readOnly}
                        title="No signatures required"
                    />
                    <DecisionOption
                        value="preset"
                        selected={signingMode === 'preset'}
                        disabled={readOnly}
                        title="Use signing flow"
                    />
                </RadioGroup>
                {signingMode === 'none' &&
                placedSlotKeys.length > 0 &&
                !readOnly ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7 text-[11px]"
                        onClick={onRemoveSignaturePlacements}
                    >
                        Remove signature placements
                    </Button>
                ) : null}
                {signingMode === 'preset' ? (
                    <div className="space-y-2">
                        <AppSelect
                            value={
                                signingPresetId !== null
                                    ? String(signingPresetId)
                                    : ''
                            }
                            onValueChange={(value) =>
                                onSigningPresetChange(Number(value))
                            }
                            disabled={readOnly}
                            placeholder="Signing flow"
                        >
                            {signingPresets.map((preset) => (
                                <AppSelectItem
                                    key={preset.id}
                                    value={String(preset.id)}
                                >
                                    {preset.name}
                                    {!preset.is_active ? ' (inactive)' : ''}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {canCreateSigningPresets && !readOnly ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 px-2 text-[11px]"
                                onClick={onCreateSigningPreset}
                            >
                                <Plus className="mr-1 size-3" />
                                Create signing flow
                            </Button>
                        ) : null}
                        {signingStatuses.length > 0 ? (
                            <ol className="space-y-2">
                                {signingStatuses.map((step) => (
                                    <li
                                        key={step.slotKey}
                                        className="rounded-md border border-border/70 px-2 py-1.5"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <span>
                                                {step.placed ? '✓' : '⚠'}{' '}
                                                {step.sequence} {step.label}
                                            </span>
                                        </div>
                                        {!step.placed && !readOnly ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="mt-1.5 h-6 px-2 text-[11px]"
                                                onClick={() =>
                                                    onPlaceSlot(step.slotKey)
                                                }
                                            >
                                                Place on PDF
                                            </Button>
                                        ) : null}
                                    </li>
                                ))}
                            </ol>
                        ) : null}
                    </div>
                ) : null}
            </section>

            <section className="space-y-2">
                <p className="text-[11px] font-semibold tracking-wide text-foreground uppercase">
                    Execution order
                </p>
                <ol className="space-y-1 text-[11px] text-muted-foreground">
                    {order.map((step, index) => (
                        <li key={step}>
                            {step}
                            {index < order.length - 1 ? ' ↓' : ''}
                        </li>
                    ))}
                </ol>
            </section>
        </div>
    );
}

function DecisionOption({
    value,
    selected,
    disabled,
    title,
}: {
    value: string;
    selected: boolean;
    disabled: boolean;
    title: string;
}) {
    return (
        <RadioItem
            value={value}
            disabled={disabled}
            className={cn(
                'rounded-lg border bg-card/70 p-2.5 text-left text-xs outline-none',
                disabled ? 'cursor-default opacity-80' : 'cursor-pointer',
                selected
                    ? 'border-primary shadow-xs ring-1 ring-primary'
                    : 'border-border/80 hover:border-border hover:bg-card',
            )}
        >
            <div className="font-medium text-foreground">{title}</div>
        </RadioItem>
    );
}
