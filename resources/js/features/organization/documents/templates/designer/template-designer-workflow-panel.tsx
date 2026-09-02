import { ChevronDown, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import {
    executionOrderSteps,
    reviewSectionStatus,
    signingSectionStatus,
    signingStepPlacementStatuses,
    workflowFocusKey,
} from '../lib/template-workflow';
import type {
    WorkflowFocusTarget,
    WorkflowSectionStatus,
} from '../lib/template-workflow';
import type {
    DesignerSigningPreset,
    DesignerWorkflowPreset,
    TemplateAutomationMode,
} from '../types';
import { TemplateApprovalFlowSteps } from './template-approval-flow-steps';
import { TemplateExecutionOrder } from './template-execution-order';
import { TemplateSigningFlowSteps } from './template-signing-flow-steps';
import { TemplateWorkflowDecisionCards } from './template-workflow-decision-cards';

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
    selectedSlotKey: string | null;
    pendingSlotKey: string | null;
    canCreateWorkflowPresets: boolean;
    canCreateSigningPresets: boolean;
    focusTarget: WorkflowFocusTarget | null;
    onFocusHandled: () => void;
    onWorkflowModeChange: (mode: TemplateAutomationMode) => void;
    onWorkflowPresetChange: (id: number | null) => void;
    onSigningModeChange: (mode: TemplateAutomationMode) => void;
    onSigningPresetChange: (id: number | null) => void;
    onCreateWorkflowPreset: () => void;
    onCreateSigningPreset: () => void;
    onLocateSlot: (slotKey: string) => void;
    onPlaceSlot: (slotKey: string, label: string) => void;
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
    selectedSlotKey,
    pendingSlotKey,
    canCreateWorkflowPresets,
    canCreateSigningPresets,
    focusTarget,
    onFocusHandled,
    onWorkflowModeChange,
    onWorkflowPresetChange,
    onSigningModeChange,
    onSigningPresetChange,
    onCreateWorkflowPreset,
    onCreateSigningPreset,
    onLocateSlot,
    onPlaceSlot,
    onRemoveSignaturePlacements,
    isLegacy,
}: Props) {
    const panelRef = useRef<HTMLDivElement>(null);
    const [reviewOpen, setReviewOpen] = useState(true);
    const [signingOpen, setSigningOpen] = useState(true);
    const [orderOpen, setOrderOpen] = useState(false);

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
    const missingPlacementCount = signingStatuses.filter(
        (step) => !step.placed,
    ).length;
    const reviewStatus = reviewSectionStatus({
        readOnly,
        mode: workflowMode,
        presetId: workflowPresetId,
    });
    const signingStatus = signingSectionStatus({
        readOnly,
        mode: signingMode,
        presetId: signingPresetId,
        missingPlacementCount,
        hasLeftoverPlacements: placedSlotKeys.length > 0,
    });
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
    const highlightedSlotKey = pendingSlotKey ?? selectedSlotKey;
    const focusedSlotKey =
        focusTarget?.section === 'signing-step' ? focusTarget.slotKey : null;
    const highlightKey = focusTarget ? workflowFocusKey(focusTarget) : null;
    const reviewForcedOpen =
        focusTarget?.section === 'review' ||
        focusTarget?.section === 'review-preset';
    const signingForcedOpen =
        focusTarget?.section === 'signing' ||
        focusTarget?.section === 'signing-preset' ||
        focusTarget?.section === 'signing-step';

    useEffect(() => {
        if (!focusTarget) {
            return;
        }

        const key = workflowFocusKey(focusTarget);
        const frame = window.requestAnimationFrame(() => {
            panelRef.current
                ?.querySelector(`[data-workflow-focus="${key}"]`)
                ?.scrollIntoView({ block: 'nearest' });
        });

        const reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        const timeout = window.setTimeout(
            () => {
                onFocusHandled();
            },
            reduced ? 50 : 1600,
        );

        return () => {
            window.cancelAnimationFrame(frame);
            window.clearTimeout(timeout);
        };
    }, [focusTarget, onFocusHandled]);

    return (
        <div ref={panelRef} className="space-y-3 text-xs">
            {isLegacy ? (
                <p className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2 text-[11px] text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-100">
                    Legacy version. No explicit workflow decision was recorded.
                </p>
            ) : null}

            <WorkflowSection
                title="Review & Approval"
                status={reviewStatus}
                open={reviewOpen || reviewForcedOpen}
                onOpenChange={setReviewOpen}
                highlighted={highlightKey === 'review'}
            >
                <div
                    data-workflow-focus="review"
                    className={cn(
                        'rounded-lg',
                        highlightKey === 'review' &&
                            'motion-safe:ring-2 motion-safe:ring-primary/35',
                    )}
                >
                    <TemplateWorkflowDecisionCards
                        name="Review and approval"
                        value={workflowMode}
                        disabled={readOnly}
                        noneTitle="No review required"
                        presetTitle="Use approval flow"
                        presetDescription={selectedWorkflow?.name}
                        highlighted={highlightKey === 'review'}
                        onChange={onWorkflowModeChange}
                    />
                </div>
                {workflowMode === 'preset' ? (
                    <div
                        className="space-y-2"
                        data-workflow-focus="review-preset"
                    >
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
                            size="sm"
                            className={cn(
                                highlightKey === 'review-preset' &&
                                    'motion-safe:ring-2 motion-safe:ring-primary/35',
                            )}
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
                            <TemplateApprovalFlowSteps
                                presetName={selectedWorkflow.name}
                                stages={selectedWorkflow.stages}
                            />
                        ) : null}
                    </div>
                ) : null}
            </WorkflowSection>

            <WorkflowSection
                title="Signing"
                status={signingStatus}
                open={signingOpen || signingForcedOpen}
                onOpenChange={setSigningOpen}
                highlighted={highlightKey === 'signing'}
            >
                <div
                    data-workflow-focus="signing"
                    className={cn(
                        'space-y-2 rounded-lg',
                        highlightKey === 'signing' &&
                            'motion-safe:ring-2 motion-safe:ring-primary/35',
                    )}
                >
                    <TemplateWorkflowDecisionCards
                        name="Signing"
                        value={signingMode}
                        disabled={readOnly}
                        noneTitle="No signatures required"
                        presetTitle="Use signing flow"
                        presetDescription={selectedSigning?.name}
                        highlighted={highlightKey === 'signing'}
                        onChange={onSigningModeChange}
                    />
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
                        <div
                            className="space-y-2"
                            data-workflow-focus="signing-preset"
                        >
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
                                size="sm"
                                className={cn(
                                    highlightKey === 'signing-preset' &&
                                        'motion-safe:ring-2 motion-safe:ring-primary/35',
                                )}
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
                                <TemplateSigningFlowSteps
                                    presetName={selectedSigning?.name ?? ''}
                                    steps={signingStatuses}
                                    readOnly={readOnly}
                                    highlightedSlotKey={highlightedSlotKey}
                                    focusedSlotKey={focusedSlotKey}
                                    onLocateSlot={onLocateSlot}
                                    onPlaceSlot={onPlaceSlot}
                                />
                            ) : null}
                        </div>
                    ) : null}
                </div>
            </WorkflowSection>

            <WorkflowSection
                title="Execution order"
                status={null}
                open={orderOpen}
                onOpenChange={setOrderOpen}
                highlighted={false}
            >
                <TemplateExecutionOrder steps={order} />
            </WorkflowSection>
        </div>
    );
}

function WorkflowSection({
    title,
    status,
    open,
    onOpenChange,
    highlighted,
    children,
}: {
    title: string;
    status: WorkflowSectionStatus | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    highlighted: boolean;
    children: ReactNode;
}) {
    return (
        <Collapsible open={open} onOpenChange={onOpenChange}>
            <section className={cn('space-y-2', highlighted && 'rounded-lg')}>
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        className="flex w-full items-center gap-2 rounded-md py-0.5 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        aria-expanded={open}
                    >
                        <ChevronDown
                            className={cn(
                                'size-3.5 shrink-0 text-muted-foreground motion-safe:transition-transform',
                                !open && '-rotate-90',
                            )}
                            aria-hidden
                        />
                        <span className="min-w-0 flex-1 text-[11px] font-semibold tracking-wide text-foreground uppercase">
                            {title}
                        </span>
                        {status ? <SectionStatusBadge status={status} /> : null}
                    </button>
                </CollapsibleTrigger>
                <CollapsibleContent className="space-y-2">
                    {children}
                </CollapsibleContent>
            </section>
        </Collapsible>
    );
}

function SectionStatusBadge({ status }: { status: WorkflowSectionStatus }) {
    const variant =
        status.kind === 'configured'
            ? 'success'
            : status.kind === 'read_only'
              ? 'outline'
              : 'warning';

    return (
        <Badge variant={variant} className="h-5 px-1.5 text-[10px]">
            {status.kind === 'configured' ? '✓ ' : null}
            {status.kind === 'decision_required' || status.kind === 'issues'
                ? '⚠ '
                : null}
            {status.label}
        </Badge>
    );
}
