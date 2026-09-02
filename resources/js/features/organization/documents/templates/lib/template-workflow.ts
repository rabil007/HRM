export const SAVE_DRAFT_LABEL = 'Save Draft';

export const READINESS_CODES = {
    workflowDecisionMissing: 'workflow_decision_missing',
    signingDecisionMissing: 'signing_decision_missing',
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
