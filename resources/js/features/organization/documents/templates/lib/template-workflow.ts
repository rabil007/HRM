import type { LayoutValidationStatus } from './layout-validation';

export type { LayoutValidationStatus };

export const SAVE_DRAFT_LABEL = 'Save Draft';

export const READINESS_CODES = {
    workflowDecisionMissing: 'workflow_decision_missing',
    workflowPresetMissing: 'workflow_preset_missing',
    signingDecisionMissing: 'signing_decision_missing',
    signingPresetMissing: 'signing_preset_missing',
    signingPlacementMissing: 'signing_placement_missing',
    signingPlacementsConflict: 'signing_placements_conflict',
    unsavedChanges: 'unsaved_changes',
    legacyWorkflowUnconfigured: 'legacy_workflow_unconfigured',
    legacySigningUnconfigured: 'legacy_signing_unconfigured',
} as const;

export type TemplateAutomationMode = 'none' | 'preset' | null;

export function displayedAutomationMode(
    stored: TemplateAutomationMode | undefined,
    presetId: number | null | undefined,
): TemplateAutomationMode {
    if (stored === 'none' || stored === 'preset') {
        return stored;
    }

    if (presetId != null) {
        return 'preset';
    }

    return null;
}

export function executionOrderSteps(
    workflowMode: TemplateAutomationMode,
    signingMode: TemplateAutomationMode,
): string[] {
    const steps = ['Generated'];

    if (workflowMode === 'preset') {
        steps.push('Review & Approval');
    }

    if (signingMode === 'preset') {
        steps.push('Signing');
    }

    steps.push('Completed');

    return steps;
}

export type SigningStepPlacementStatus = {
    sequence: number;
    slotKey: string;
    label: string;
    placed: boolean;
    page: number | null;
};

export function signingStepPlacementStatuses(
    steps: Array<{ sequence: number; slot_key: string; display_label: string }>,
    placedSlotKeys: Iterable<string>,
    slotPages: Record<string, number> = {},
): SigningStepPlacementStatus[] {
    const placed = new Set(placedSlotKeys);

    return steps.map((step) => ({
        sequence: step.sequence,
        slotKey: step.slot_key,
        label: step.display_label,
        placed: placed.has(step.slot_key),
        page: slotPages[step.slot_key] ?? null,
    }));
}

export function nextSignatureSlotToPlace(
    targetSlotKey: string,
    existingSlotKeys: Iterable<string>,
): { action: 'select' | 'add'; slotKey: string; role: string } {
    const existing = new Set(existingSlotKeys);

    if (existing.has(targetSlotKey)) {
        return {
            action: 'select',
            slotKey: targetSlotKey,
            role: roleFromSlotKey(targetSlotKey),
        };
    }

    return {
        action: 'add',
        slotKey: targetSlotKey,
        role: roleFromSlotKey(targetSlotKey),
    };
}

export function roleFromSlotKey(slotKey: string): string {
    if (slotKey === 'subject') {
        return 'subject';
    }

    if (slotKey.startsWith('manager_')) {
        return 'manager';
    }

    return 'company_signatory';
}

export type LocalWorkflowState = {
    workflowMode: TemplateAutomationMode;
    workflowPresetId: number | null;
    signingMode: TemplateAutomationMode;
    signingPresetId: number | null;
    hasUnsavedChanges: boolean;
    hasSignaturePlacements: boolean;
    missingSigningSlotKeys: string[];
};

export type LocalReadinessIssue = {
    code: string;
    section: 'workflow' | 'signing' | 'version';
    severity: 'error' | 'warning' | 'info';
    blocking: boolean;
    message: string;
    meta: Record<string, unknown>;
};

export type WorkflowSectionStatusKind =
    | 'configured'
    | 'decision_required'
    | 'issues'
    | 'read_only';

export type WorkflowSectionStatus = {
    kind: WorkflowSectionStatusKind;
    label: string;
    count?: number;
};

export type WorkflowFocusTarget =
    | { section: 'review' }
    | { section: 'review-preset' }
    | { section: 'signing' }
    | { section: 'signing-preset' }
    | { section: 'signing-step'; slotKey: string };

export type ReadinessFixKind =
    | 'save_draft'
    | 'configure_review'
    | 'configure_signing'
    | 'choose_review_flow'
    | 'choose_signing_flow'
    | 'place_on_pdf'
    | 'remove_signature_placements';

export type ReadinessFixAction = {
    kind: ReadinessFixKind;
    label: string;
    focus: WorkflowFocusTarget | null;
    slotKey: string | null;
};

export type ReadinessDisplayState = {
    kind: 'issues' | 'complete_unsaved' | 'ready';
    label: string;
    detail: string | null;
};

export function reviewSectionStatus(args: {
    readOnly: boolean;
    mode: TemplateAutomationMode;
    presetId: number | null;
}): WorkflowSectionStatus {
    if (args.readOnly) {
        return { kind: 'read_only', label: 'Read only' };
    }

    if (args.mode == null) {
        return { kind: 'decision_required', label: 'Decision required' };
    }

    if (args.mode === 'preset' && args.presetId == null) {
        return { kind: 'issues', label: '1 issue', count: 1 };
    }

    return { kind: 'configured', label: 'Configured' };
}

export function signingSectionStatus(args: {
    readOnly: boolean;
    mode: TemplateAutomationMode;
    presetId: number | null;
    missingPlacementCount: number;
    hasLeftoverPlacements: boolean;
}): WorkflowSectionStatus {
    if (args.readOnly) {
        return { kind: 'read_only', label: 'Read only' };
    }

    if (args.mode == null) {
        return { kind: 'decision_required', label: 'Decision required' };
    }

    if (args.mode === 'none' && args.hasLeftoverPlacements) {
        return { kind: 'issues', label: '1 issue', count: 1 };
    }

    if (args.mode === 'preset' && args.presetId == null) {
        return { kind: 'issues', label: '1 issue', count: 1 };
    }

    if (args.mode === 'preset' && args.missingPlacementCount > 0) {
        const count = args.missingPlacementCount;

        return {
            kind: 'issues',
            label: `${count} ${count === 1 ? 'issue' : 'issues'}`,
            count,
        };
    }

    return { kind: 'configured', label: 'Configured' };
}

export function signingStepPlacementCopy(placed: boolean): string {
    return placed ? 'Placement configured' : 'Signature placement missing';
}

export function workflowFocusKey(target: WorkflowFocusTarget): string {
    if (target.section === 'signing-step') {
        return `signing-step:${target.slotKey}`;
    }

    return target.section;
}

export function readinessFixAction(issue: {
    code: string;
    meta?: Record<string, unknown>;
}): ReadinessFixAction | null {
    const meta = issue.meta ?? {};
    const slotKey =
        typeof meta.slot_key === 'string' && meta.slot_key !== ''
            ? meta.slot_key
            : null;

    switch (issue.code) {
        case READINESS_CODES.workflowDecisionMissing:
            return {
                kind: 'configure_review',
                label: 'Configure',
                focus: { section: 'review' },
                slotKey: null,
            };
        case READINESS_CODES.signingDecisionMissing:
            return {
                kind: 'configure_signing',
                label: 'Configure',
                focus: { section: 'signing' },
                slotKey: null,
            };
        case READINESS_CODES.workflowPresetMissing:
            return {
                kind: 'choose_review_flow',
                label: 'Choose flow',
                focus: { section: 'review-preset' },
                slotKey: null,
            };
        case READINESS_CODES.signingPresetMissing:
            return {
                kind: 'choose_signing_flow',
                label: 'Choose flow',
                focus: { section: 'signing-preset' },
                slotKey: null,
            };
        case READINESS_CODES.signingPlacementMissing:
            return {
                kind: 'place_on_pdf',
                label: 'Place on PDF',
                focus: slotKey
                    ? { section: 'signing-step', slotKey }
                    : { section: 'signing' },
                slotKey,
            };
        case READINESS_CODES.unsavedChanges:
            return {
                kind: 'save_draft',
                label: 'Save Draft',
                focus: null,
                slotKey: null,
            };
        case READINESS_CODES.signingPlacementsConflict:
            return {
                kind: 'remove_signature_placements',
                label: 'Remove signature placements',
                focus: { section: 'signing' },
                slotKey: null,
            };
        default:
            break;
    }

    const fix = String(meta.fix ?? '');

    if (fix === 'save_draft') {
        return {
            kind: 'save_draft',
            label: 'Save Draft',
            focus: null,
            slotKey: null,
        };
    }

    if (fix === 'configure_workflow') {
        return {
            kind: 'configure_review',
            label: 'Configure',
            focus: { section: 'review' },
            slotKey: null,
        };
    }

    if (fix === 'configure_signing') {
        return {
            kind: 'configure_signing',
            label: 'Configure',
            focus: { section: 'signing' },
            slotKey: null,
        };
    }

    if (fix === 'place_on_pdf') {
        return {
            kind: 'place_on_pdf',
            label: 'Place on PDF',
            focus: slotKey
                ? { section: 'signing-step', slotKey }
                : { section: 'signing' },
            slotKey,
        };
    }

    if (fix === 'remove_signature_placements') {
        return {
            kind: 'remove_signature_placements',
            label: 'Remove signature placements',
            focus: { section: 'signing' },
            slotKey: null,
        };
    }

    return null;
}

export function configurationBlockingCount(
    issues: Array<{ blocking: boolean; code: string }>,
): number {
    return issues.filter(
        (issue) =>
            issue.blocking && issue.code !== READINESS_CODES.unsavedChanges,
    ).length;
}

export function combinedPublishIssueLabel(args: {
    configurationBlockingCount: number;
    layoutStatus: LayoutValidationStatus;
    layoutIssueCount: number;
}): { kind: 'issues' | 'stale' | 'ready'; label: string } | null {
    if (args.layoutStatus === 'unavailable') {
        return { kind: 'issues', label: 'Validation unavailable' };
    }

    const layoutBlocking =
        args.layoutStatus === 'invalid' ? args.layoutIssueCount : 0;
    const total = args.configurationBlockingCount + layoutBlocking;

    if (total > 0) {
        if (args.configurationBlockingCount === 0) {
            return {
                kind: 'issues',
                label:
                    layoutBlocking === 1
                        ? '1 layout issue'
                        : `${layoutBlocking} layout issues`,
            };
        }

        if (layoutBlocking === 0) {
            return {
                kind: 'issues',
                label: `${args.configurationBlockingCount} ${args.configurationBlockingCount === 1 ? 'issue' : 'issues'}`,
            };
        }

        return {
            kind: 'issues',
            label: `${total} issues`,
        };
    }

    if (
        args.layoutStatus === 'stale' ||
        args.layoutStatus === 'idle' ||
        args.layoutStatus === 'checking'
    ) {
        return { kind: 'stale', label: 'Validation required' };
    }

    return null;
}

export function readinessDisplayState(args: {
    configurationBlockingCount: number;
    hasUnsavedChanges: boolean;
    serverReady: boolean;
    layoutStatus?: LayoutValidationStatus;
    layoutIssueCount?: number;
}): ReadinessDisplayState {
    const combined = combinedPublishIssueLabel({
        configurationBlockingCount: args.configurationBlockingCount,
        layoutStatus: args.layoutStatus ?? 'valid',
        layoutIssueCount: args.layoutIssueCount ?? 0,
    });

    if (combined?.kind === 'issues') {
        return {
            kind: 'issues',
            label: combined.label,
            detail: null,
        };
    }

    if (args.hasUnsavedChanges) {
        return {
            kind: 'complete_unsaved',
            label: 'Configuration complete',
            detail: 'Unsaved changes',
        };
    }

    if (combined?.kind === 'stale') {
        return {
            kind: 'issues',
            label: combined.label,
            detail: null,
        };
    }

    return {
        kind: 'ready',
        label: args.serverReady ? 'Ready to publish' : 'Ready',
        detail: null,
    };
}

export function visibleReadinessIssues<
    TPersisted extends { section: string },
    TLocal extends { section: string },
>(args: {
    persistedIssues: TPersisted[];
    localIssues: TLocal[];
    hasUnsavedChanges: boolean;
}): Array<TPersisted | TLocal> {
    if (!args.hasUnsavedChanges) {
        return args.persistedIssues;
    }

    return [
        ...args.persistedIssues.filter((issue) => issue.section === 'design'),
        ...args.localIssues,
    ];
}

export function localWorkflowIssues(
    state: LocalWorkflowState,
): LocalReadinessIssue[] {
    const issues: LocalReadinessIssue[] = [];

    if (state.hasUnsavedChanges) {
        issues.push({
            code: READINESS_CODES.unsavedChanges,
            section: 'version',
            severity: 'error',
            blocking: true,
            message: 'Save the draft before publishing.',
            meta: { fix: 'save_draft' },
        });
    }

    if (state.workflowMode == null) {
        issues.push({
            code: READINESS_CODES.workflowDecisionMissing,
            section: 'workflow',
            severity: 'error',
            blocking: true,
            message: 'Choose whether review and approval are required.',
            meta: { fix: 'configure_workflow' },
        });
    }

    if (state.workflowMode === 'preset' && state.workflowPresetId == null) {
        issues.push({
            code: READINESS_CODES.workflowPresetMissing,
            section: 'workflow',
            severity: 'error',
            blocking: true,
            message: 'Select an approval flow.',
            meta: { fix: 'configure_workflow' },
        });
    }

    if (state.signingMode == null) {
        issues.push({
            code: READINESS_CODES.signingDecisionMissing,
            section: 'signing',
            severity: 'error',
            blocking: true,
            message: 'Choose whether signatures are required.',
            meta: { fix: 'configure_signing' },
        });
    }

    if (state.signingMode === 'preset' && state.signingPresetId == null) {
        issues.push({
            code: READINESS_CODES.signingPresetMissing,
            section: 'signing',
            severity: 'error',
            blocking: true,
            message: 'Select a signing flow.',
            meta: { fix: 'configure_signing' },
        });
    }

    if (state.signingMode === 'none' && state.hasSignaturePlacements) {
        issues.push({
            code: READINESS_CODES.signingPlacementsConflict,
            section: 'signing',
            severity: 'error',
            blocking: true,
            message:
                'Signature placements remain on the PDF, but signing is not required.',
            meta: { fix: 'remove_signature_placements' },
        });
    }

    if (state.signingMode === 'preset') {
        for (const slotKey of state.missingSigningSlotKeys) {
            issues.push({
                code: READINESS_CODES.signingPlacementMissing,
                section: 'signing',
                severity: 'error',
                blocking: true,
                message: 'A required signature placement is missing.',
                meta: { fix: 'place_on_pdf', slot_key: slotKey },
            });
        }
    }

    return issues;
}

export function mergeReadinessBlockingCount(
    serverBlockingCount: number,
    localIssues: LocalReadinessIssue[],
    hasUnsavedChanges: boolean,
): number {
    if (!hasUnsavedChanges) {
        return serverBlockingCount;
    }

    return localIssues.filter((issue) => issue.blocking).length;
}

export const designerUiCopy = {
    propertiesTab: 'Properties',
    workflowTab: 'Workflow',
    saveDraft: SAVE_DRAFT_LABEL,
    afterGeneration: 'After generation',
    saveDesign: 'Save Design',
} as const;
