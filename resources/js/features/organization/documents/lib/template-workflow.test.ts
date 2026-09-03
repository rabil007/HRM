import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import {
    displayedAutomationMode,
    executionOrderSteps,
    localWorkflowIssues,
    mergeReadinessBlockingCount,
    nextSignatureSlotToPlace,
    READINESS_CODES,
    readinessDisplayState,
    readinessFixAction,
    reviewSectionStatus,
    roleFromSlotKey,
    SAVE_DRAFT_LABEL,
    signingSectionStatus,
    signingStepPlacementCopy,
    signingStepPlacementStatuses,
    designerUiCopy,
} from '../templates/lib/template-workflow.ts';

describe('displayedAutomationMode', () => {
    it('treats stored none as none', () => {
        assert.equal(displayedAutomationMode('none', 4), 'none');
    });

    it('treats legacy preset id with null mode as preset', () => {
        assert.equal(displayedAutomationMode(null, 4), 'preset');
    });

    it('treats null id and null mode as unconfigured', () => {
        assert.equal(displayedAutomationMode(null, null), null);
    });
});

describe('executionOrderSteps', () => {
    it('shows generated to completed when both none', () => {
        assert.deepEqual(executionOrderSteps('none', 'none'), [
            'Generated',
            'Completed',
        ]);
    });

    it('includes signing when only signing is a preset', () => {
        assert.deepEqual(executionOrderSteps('none', 'preset'), [
            'Generated',
            'Signing',
            'Completed',
        ]);
    });

    it('includes review when only review is a preset', () => {
        assert.deepEqual(executionOrderSteps('preset', 'none'), [
            'Generated',
            'Review & Approval',
            'Completed',
        ]);
    });
});

describe('signingStepPlacementStatuses', () => {
    it('marks missing signer placements', () => {
        const statuses = signingStepPlacementStatuses(
            [
                {
                    sequence: 1,
                    slot_key: 'subject',
                    display_label: 'Employee',
                },
                {
                    sequence: 2,
                    slot_key: 'company_signatory_1',
                    display_label: 'Company Signatory',
                },
            ],
            ['subject'],
        );

        assert.equal(statuses[0]?.placed, true);
        assert.equal(statuses[1]?.placed, false);
        assert.equal(statuses[1]?.slotKey, 'company_signatory_1');
        assert.equal(signingStepPlacementCopy(true), 'Placement configured');
        assert.equal(
            signingStepPlacementCopy(false),
            'Signature placement missing',
        );
        assert.equal(
            statuses[0]?.label.includes(String(statuses[0].sequence)),
            false,
        );
        assert.equal(/^\d+$/.test(statuses[0]?.label ?? ''), false);
    });
});

describe('nextSignatureSlotToPlace', () => {
    it('selects an existing slot', () => {
        const next = nextSignatureSlotToPlace('subject', ['subject']);

        assert.equal(next.action, 'select');
        assert.equal(next.slotKey, 'subject');
        assert.equal(next.role, 'subject');
    });

    it('adds the matching existing slot convention', () => {
        const next = nextSignatureSlotToPlace('company_signatory_1', [
            'subject',
        ]);

        assert.equal(next.action, 'add');
        assert.equal(next.role, 'company_signatory');
        assert.equal(roleFromSlotKey('manager_2'), 'manager');
    });
});

describe('readinessFixAction', () => {
    it('maps stable issue codes rather than English messages', () => {
        const review = readinessFixAction({
            code: READINESS_CODES.workflowDecisionMissing,
            meta: { fix: 'configure_workflow' },
        });
        const signing = readinessFixAction({
            code: READINESS_CODES.signingDecisionMissing,
            meta: { fix: 'configure_signing' },
        });
        const reviewPreset = readinessFixAction({
            code: READINESS_CODES.workflowPresetMissing,
        });
        const signingPreset = readinessFixAction({
            code: READINESS_CODES.signingPresetMissing,
        });
        const placement = readinessFixAction({
            code: READINESS_CODES.signingPlacementMissing,
            meta: { slot_key: 'company_signatory_1' },
        });
        const unsaved = readinessFixAction({
            code: READINESS_CODES.unsavedChanges,
        });

        assert.equal(review?.kind, 'configure_review');
        assert.equal(review?.label, 'Configure');
        assert.deepEqual(review?.focus, { section: 'review' });
        assert.equal(signing?.kind, 'configure_signing');
        assert.deepEqual(signing?.focus, { section: 'signing' });
        assert.equal(reviewPreset?.label, 'Choose flow');
        assert.deepEqual(reviewPreset?.focus, { section: 'review-preset' });
        assert.equal(signingPreset?.label, 'Choose flow');
        assert.deepEqual(signingPreset?.focus, { section: 'signing-preset' });
        assert.equal(placement?.kind, 'place_on_pdf');
        assert.equal(placement?.slotKey, 'company_signatory_1');
        assert.equal(unsaved?.kind, 'save_draft');
        assert.equal(unsaved?.label, 'Save Draft');
    });
});

describe('reviewSectionStatus and signingSectionStatus', () => {
    it('shows selected none and preset states', () => {
        assert.equal(
            reviewSectionStatus({
                readOnly: false,
                mode: 'none',
                presetId: null,
            }).kind,
            'configured',
        );
        assert.equal(
            reviewSectionStatus({
                readOnly: false,
                mode: 'preset',
                presetId: 4,
            }).kind,
            'configured',
        );
        assert.equal(
            reviewSectionStatus({
                readOnly: false,
                mode: null,
                presetId: null,
            }).kind,
            'decision_required',
        );
        assert.equal(
            reviewSectionStatus({
                readOnly: true,
                mode: 'preset',
                presetId: 4,
            }).kind,
            'read_only',
        );
        assert.equal(
            signingSectionStatus({
                readOnly: false,
                mode: 'none',
                presetId: null,
                missingPlacementCount: 0,
                hasLeftoverPlacements: false,
            }).kind,
            'configured',
        );
        assert.equal(
            signingSectionStatus({
                readOnly: false,
                mode: 'preset',
                presetId: 2,
                missingPlacementCount: 1,
                hasLeftoverPlacements: false,
            }).label,
            '1 issue',
        );
    });
});

describe('readinessDisplayState', () => {
    it('keeps unsaved drafts from looking ready to publish', () => {
        assert.equal(
            readinessDisplayState({
                configurationBlockingCount: 0,
                hasUnsavedChanges: true,
                serverReady: false,
            }).kind,
            'complete_unsaved',
        );
        assert.equal(
            readinessDisplayState({
                configurationBlockingCount: 0,
                hasUnsavedChanges: false,
                serverReady: true,
            }).label,
            'Ready to publish',
        );
        assert.equal(
            readinessDisplayState({
                configurationBlockingCount: 2,
                hasUnsavedChanges: true,
                serverReady: false,
            }).label,
            '2 issues',
        );
    });
});

describe('localWorkflowIssues', () => {
    it('requires explicit review and signing decisions', () => {
        const issues = localWorkflowIssues({
            workflowMode: null,
            workflowPresetId: null,
            signingMode: null,
            signingPresetId: null,
            hasUnsavedChanges: true,
            hasSignaturePlacements: false,
            missingSigningSlotKeys: [],
        });

        assert.equal(
            issues.some(
                (issue) => issue.code === READINESS_CODES.unsavedChanges,
            ),
            true,
        );
        assert.equal(
            issues.some(
                (issue) =>
                    issue.code === READINESS_CODES.workflowDecisionMissing,
            ),
            true,
        );
        assert.equal(
            issues.some(
                (issue) =>
                    issue.code === READINESS_CODES.signingDecisionMissing,
            ),
            true,
        );
    });

    it('flags leftover signature placements when signing is none', () => {
        const issues = localWorkflowIssues({
            workflowMode: 'none',
            workflowPresetId: null,
            signingMode: 'none',
            signingPresetId: null,
            hasUnsavedChanges: false,
            hasSignaturePlacements: true,
            missingSigningSlotKeys: [],
        });

        assert.equal(
            issues.some(
                (issue) =>
                    issue.code === READINESS_CODES.signingPlacementsConflict,
            ),
            true,
        );
    });

    it('flags missing presets after an explicit use-flow choice', () => {
        const issues = localWorkflowIssues({
            workflowMode: 'preset',
            workflowPresetId: null,
            signingMode: 'preset',
            signingPresetId: null,
            hasUnsavedChanges: true,
            hasSignaturePlacements: false,
            missingSigningSlotKeys: [],
        });

        assert.equal(
            issues.some(
                (issue) => issue.code === READINESS_CODES.workflowPresetMissing,
            ),
            true,
        );
        assert.equal(
            issues.some(
                (issue) => issue.code === READINESS_CODES.signingPresetMissing,
            ),
            true,
        );
    });
});

describe('mergeReadinessBlockingCount', () => {
    it('uses local issues while the draft is unsaved', () => {
        assert.equal(
            mergeReadinessBlockingCount(
                0,
                [
                    {
                        code: READINESS_CODES.unsavedChanges,
                        section: 'version',
                        severity: 'error',
                        blocking: true,
                        message: 'Save the draft before publishing.',
                        meta: {},
                    },
                ],
                true,
            ),
            1,
        );
    });
});

describe('designer labels', () => {
    it('uses Save Draft and Properties / Workflow tabs', () => {
        assert.equal(SAVE_DRAFT_LABEL, 'Save Draft');
        assert.equal(designerUiCopy.propertiesTab, 'Properties');
        assert.equal(designerUiCopy.workflowTab, 'Workflow');
        assert.equal(designerUiCopy.saveDesign, 'Save Design');
        assert.equal(designerUiCopy.afterGeneration, 'After generation');
    });
});

describe('designer and templates list copy', () => {
    it('shows Save Draft and Workflow in the designer source', () => {
        const here = path.dirname(fileURLToPath(import.meta.url));
        const designer = readFileSync(
            path.join(
                here,
                '../templates/designer/template-pdf-designer-dialog.tsx',
            ),
            'utf8',
        );

        assert.equal(designer.includes('designerUiCopy.saveDraft'), true);
        assert.equal(designer.includes('Save Design'), false);
        assert.equal(designer.includes('designerUiCopy.workflowTab'), true);
        assert.equal(designer.includes('TemplateDesignerWorkflowPanel'), true);
        assert.equal(designer.includes('TemplateReadinessIndicator'), true);
        assert.equal(designer.includes('markWorkflowDirty'), true);
        assert.equal(designer.includes('publishBlocked'), true);
        assert.equal(designer.includes('placeSlotOnPdf'), true);
        assert.equal(designer.includes('locateSignatureSlot'), true);
        assert.equal(designer.includes('setPendingPlacement'), true);
        assert.equal(designer.includes('readinessFixAction'), true);
        assert.equal(designer.includes('pendingPlacementInstruction'), true);
        assert.equal(designer.includes('Click on the PDF to place'), true);
        assert.equal(designer.includes('addRoleSlot(next.role'), false);
        assert.equal(designer.includes('removeSignaturePlacement'), true);
        assert.equal(designer.includes('groupSignatureSlots'), true);
        assert.equal(designer.includes('serializeSignaturePlacements'), true);
    });

    it('keeps workflow decision, step, and readiness copy in focused components', () => {
        const here = path.dirname(fileURLToPath(import.meta.url));
        const panel = readFileSync(
            path.join(
                here,
                '../templates/designer/template-designer-workflow-panel.tsx',
            ),
            'utf8',
        );
        const decisions = readFileSync(
            path.join(
                here,
                '../templates/designer/template-workflow-decision-cards.tsx',
            ),
            'utf8',
        );
        const signingSteps = readFileSync(
            path.join(
                here,
                '../templates/designer/template-signing-flow-steps.tsx',
            ),
            'utf8',
        );
        const approvalSteps = readFileSync(
            path.join(
                here,
                '../templates/designer/template-approval-flow-steps.tsx',
            ),
            'utf8',
        );
        const readiness = readFileSync(
            path.join(
                here,
                '../templates/designer/template-readiness-indicator.tsx',
            ),
            'utf8',
        );

        assert.equal(panel.includes('No review required'), true);
        assert.equal(panel.includes('Use approval flow'), true);
        assert.equal(panel.includes('No signatures required'), true);
        assert.equal(panel.includes('Use signing flow'), true);
        assert.equal(panel.includes('TemplateWorkflowDecisionCards'), true);
        assert.equal(panel.includes('TemplateApprovalFlowSteps'), true);
        assert.equal(panel.includes('TemplateSigningFlowSteps'), true);
        assert.equal(panel.includes('readOnly'), true);
        assert.equal(decisions.includes('data-selected'), true);
        assert.equal(decisions.includes('RadioItem'), true);
        assert.equal(approvalSteps.includes('action_label'), true);
        assert.equal(signingSteps.includes('signingStepPlacementCopy'), true);
        assert.equal(signingSteps.includes('Place on PDF'), true);
        assert.equal(signingSteps.includes('!readOnly'), true);
        assert.equal(signingSteps.includes('onLocateSlot'), true);
        assert.equal(signingSteps.includes('onPlaceSlot'), true);
        assert.equal(readiness.includes('readinessFixAction'), true);
        assert.equal(readiness.includes('readinessDisplayState'), true);
        assert.equal(readiness.includes('canMutate'), true);
        assert.equal(panel.includes('preset.id'), true);
        assert.equal(panel.includes('stage.id'), false);
        assert.equal(signingSteps.includes('step.id'), false);
    });

    it('removes After generation and list Publish from Templates', () => {
        const here = path.dirname(fileURLToPath(import.meta.url));
        const templates = readFileSync(
            path.join(here, '../templates/documents-templates-content.tsx'),
            'utf8',
        );

        assert.equal(templates.includes('After generation'), false);
        assert.equal(templates.includes('TemplateAutomationSheet'), false);
        assert.equal(templates.includes('handlePublishDraft'), false);
        assert.equal(templates.includes('Open Designer'), true);
    });
});
