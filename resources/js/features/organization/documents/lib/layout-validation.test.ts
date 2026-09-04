import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    layoutIssuePlacementIds,
    layoutOverflowIssueCount,
    layoutPublishBlockMessage,
    layoutReadinessSectionCopy,
    layoutSavedDraftMessage,
    layoutValidateButtonLabel,
    applyLayoutRunIfCurrent,
    isTerminalLayoutRunStatus,
    layoutValidationFingerprint,
    layoutValidationStateFromResult,
    layoutValidationStateFromRun,
    normalizeLayoutPreflightResult,
    parseLayoutValidationRunPayload,
} from '../templates/lib/layout-validation.ts';
import type {
    LayoutPreflightResult,
    LayoutValidationRun,
} from '../templates/lib/layout-validation.ts';
import { combinedPublishIssueLabel } from '../templates/lib/template-workflow.ts';

const sampleResult = (
    overrides: Partial<LayoutPreflightResult> = {},
): LayoutPreflightResult => ({
    status: 'invalid',
    valid: false,
    mode: 'sample',
    validated_with: { mode: 'sample' },
    effective_font_sizes: { emirates_id_en: null },
    issues: [
        {
            code: 'LAYOUT_OVERFLOW',
            severity: 'error',
            placement_id: 'emirates_id_en',
            field_key: '{{emirates_id}}',
            field_label: 'Emirates ID',
            page: 1,
            message: 'Emirates ID does not fit the configured field on page 1.',
        },
    ],
    fit_count: 0,
    overflow_count: 1,
    reference: null,
    ...overrides,
});

describe('layoutValidateButtonLabel', () => {
    it('shows the idle validate action', () => {
        assert.equal(layoutValidateButtonLabel('idle'), 'Validate template');
    });

    it('shows validating, valid, invalid, and stale labels', () => {
        assert.equal(layoutValidateButtonLabel('checking'), 'Validating…');
        assert.equal(layoutValidateButtonLabel('valid'), 'Layout valid');
        assert.equal(layoutValidateButtonLabel('invalid', 1), '1 layout issue');
        assert.equal(
            layoutValidateButtonLabel('invalid', 3),
            '3 layout issues',
        );
        assert.equal(
            layoutValidateButtonLabel('unavailable'),
            'Validation unavailable',
        );
        assert.equal(layoutValidateButtonLabel('stale'), 'Validation required');
    });
});

describe('layoutReadinessSectionCopy', () => {
    it('keeps layout status out of a second toolbar label', () => {
        assert.deepEqual(layoutReadinessSectionCopy('valid'), {
            kind: 'ok',
            summary: 'No issues',
            detail: null,
        });
        assert.deepEqual(layoutReadinessSectionCopy('invalid', 1), {
            kind: 'issues',
            summary: '1 layout issue',
            detail: null,
        });
        assert.deepEqual(layoutReadinessSectionCopy('unavailable'), {
            kind: 'unavailable',
            summary: 'Validation unavailable',
            detail: 'The PDF validation engine could not complete the layout check.',
        });
        assert.deepEqual(layoutReadinessSectionCopy('idle'), {
            kind: 'pending',
            summary: 'Validation required',
            detail: null,
        });
    });
});

describe('layoutValidationFingerprint', () => {
    it('changes after a placement move', () => {
        const before = layoutValidationFingerprint(1, [
            {
                id: 'emirates_id_en',
                type: 'field',
                page: 1,
                x: 0.1,
                y: 0.1,
                width: 0.2,
                height: 0.04,
                font_size: 12,
                font_weight: 'normal',
                text_align: 'left',
                field: '{{emirates_id}}',
            },
        ]);
        const after = layoutValidationFingerprint(1, [
            {
                id: 'emirates_id_en',
                type: 'field',
                page: 1,
                x: 0.2,
                y: 0.1,
                width: 0.2,
                height: 0.04,
                font_size: 12,
                font_weight: 'normal',
                text_align: 'left',
                field: '{{emirates_id}}',
            },
        ]);

        assert.notEqual(before, after);
    });

    it('changes when the version changes', () => {
        const placements = [
            {
                id: 'p1',
                type: 'field' as const,
                page: 1,
                x: 0.1,
                y: 0.1,
                width: 0.2,
                height: 0.04,
                font_size: 12,
                font_weight: 'normal',
                text_align: 'left',
                field: '{{employee_name}}',
            },
        ];

        assert.notEqual(
            layoutValidationFingerprint(1, placements),
            layoutValidationFingerprint(2, placements),
        );
    });

    it('changes when font family or static text changes', function () {
        const base = {
            id: 'box_1',
            type: 'text' as const,
            page: 1,
            x: 0.1,
            y: 0.1,
            width: 0.2,
            height: 0.04,
            font_size: 12,
            font_weight: 'normal',
            text_align: 'left',
            font_family: 'sans',
            text_content: 'Hello',
        };

        assert.notEqual(
            layoutValidationFingerprint(1, [base]),
            layoutValidationFingerprint(1, [{ ...base, font_family: 'serif' }]),
        );
        assert.notEqual(
            layoutValidationFingerprint(1, [base]),
            layoutValidationFingerprint(1, [
                { ...base, text_content: 'Hello world' },
            ]),
        );
    });
});

describe('layoutIssuePlacementIds', () => {
    it('collects exact overflowing placement ids', () => {
        const ids = layoutIssuePlacementIds(sampleResult());

        assert.equal(ids.has('emirates_id_en'), true);
        assert.equal(ids.size, 1);
    });

    it('does not highlight fields for engine failures', () => {
        const unavailable = sampleResult({
            status: 'unavailable',
            issues: [
                {
                    code: 'TEMPLATE_LAYOUT_VALIDATION_UNAVAILABLE',
                    severity: 'error',
                    placement_id: null,
                    field_key: null,
                    field_label: null,
                    page: null,
                    message:
                        'The PDF validation engine could not complete the layout check.',
                    reference: 'LAY-01TEST',
                },
            ],
            overflow_count: 0,
            reference: 'LAY-01TEST',
        });

        assert.equal(layoutIssuePlacementIds(unavailable).size, 0);
        assert.equal(layoutOverflowIssueCount(unavailable), 0);
        assert.equal(
            layoutPublishBlockMessage(unavailable),
            'Layout validation could not be completed. Publishing is unavailable until the validation check succeeds.',
        );
        assert.equal(
            layoutSavedDraftMessage(unavailable),
            'Draft saved · Validation unavailable',
        );
    });
});

describe('layoutValidationStateFromResult', () => {
    it('retries from unavailable to valid or overflow', () => {
        const fingerprint = 'fp-1';
        const unavailable = layoutValidationStateFromResult(
            sampleResult({
                status: 'unavailable',
                issues: [
                    {
                        code: 'TEMPLATE_LAYOUT_VALIDATION_UNAVAILABLE',
                        severity: 'error',
                        placement_id: null,
                        field_key: null,
                        field_label: null,
                        page: null,
                        message:
                            'The PDF validation engine could not complete the layout check.',
                    },
                ],
                overflow_count: 0,
            }),
            fingerprint,
        );
        const valid = layoutValidationStateFromResult(
            sampleResult({
                status: 'valid',
                valid: true,
                issues: [],
                overflow_count: 0,
                fit_count: 1,
            }),
            fingerprint,
        );
        const overflow = layoutValidationStateFromResult(
            sampleResult(),
            fingerprint,
        );

        assert.equal(unavailable.status, 'unavailable');
        assert.equal(valid.status, 'valid');
        assert.equal(overflow.status, 'invalid');
        assert.equal(
            layoutValidateButtonLabel('unavailable'),
            'Validation unavailable',
        );
        assert.equal(layoutValidateButtonLabel('invalid', 1), '1 layout issue');
    });

    it('treats a missing status with the unavailable code as engine failure', () => {
        const normalized = normalizeLayoutPreflightResult({
            valid: false,
            issues: [
                {
                    code: 'TEMPLATE_LAYOUT_VALIDATION_UNAVAILABLE',
                    severity: 'error',
                    placement_id: null,
                    field_key: null,
                    field_label: null,
                    page: null,
                    message:
                        'The PDF validation engine could not complete the layout check.',
                },
            ],
        });

        assert.equal(normalized.status, 'unavailable');
        assert.equal(normalized.valid, false);
    });
});

describe('combinedPublishIssueLabel', () => {
    it('names layout-only and combined issue counts', () => {
        assert.deepEqual(
            combinedPublishIssueLabel({
                configurationBlockingCount: 0,
                layoutStatus: 'invalid',
                layoutIssueCount: 1,
            }),
            { kind: 'issues', label: '1 layout issue' },
        );
        assert.deepEqual(
            combinedPublishIssueLabel({
                configurationBlockingCount: 2,
                layoutStatus: 'invalid',
                layoutIssueCount: 1,
            }),
            { kind: 'issues', label: '3 issues' },
        );
        assert.deepEqual(
            combinedPublishIssueLabel({
                configurationBlockingCount: 0,
                layoutStatus: 'stale',
                layoutIssueCount: 0,
            }),
            { kind: 'stale', label: 'Validation required' },
        );
        assert.deepEqual(
            combinedPublishIssueLabel({
                configurationBlockingCount: 0,
                layoutStatus: 'unavailable',
                layoutIssueCount: 1,
            }),
            { kind: 'issues', label: 'Validation unavailable' },
        );
    });
});

const sampleRun = (
    overrides: Partial<LayoutValidationRun> = {},
): LayoutValidationRun => ({
    id: 12,
    status: 'queued',
    mode: 'sample',
    authoritative: true,
    valid: false,
    validated_with: { mode: 'sample' },
    effective_font_sizes: {},
    issues: [],
    fit_count: 0,
    overflow_count: 0,
    reference: null,
    ...overrides,
});

describe('async layout validation runs', () => {
    it('treats queued and processing as non-terminal', () => {
        assert.equal(isTerminalLayoutRunStatus('queued'), false);
        assert.equal(isTerminalLayoutRunStatus('processing'), false);
        assert.equal(isTerminalLayoutRunStatus('valid'), true);
    });

    it('restores checking from an in-flight run', () => {
        const state = layoutValidationStateFromRun(sampleRun(), 'fp-current');

        assert.deepEqual(state, {
            status: 'checking',
            fingerprint: 'fp-current',
            runId: 12,
        });
    });

    it('ignores a completed run when the canvas fingerprint changed', () => {
        const next = applyLayoutRunIfCurrent(
            sampleRun({
                status: 'valid',
                valid: true,
            }),
            'fp-request',
            'fp-moved',
        );

        assert.equal(next.status, 'stale');
    });

    it('applies a valid run only when the canvas still matches', () => {
        const next = applyLayoutRunIfCurrent(
            sampleRun({
                status: 'valid',
                valid: true,
            }),
            'fp-current',
            'fp-current',
        );

        assert.equal(next.status, 'valid');
    });

    it('parses a queued POST envelope', () => {
        const run = parseLayoutValidationRunPayload({
            run: sampleRun({ status: 'queued' }),
        });

        assert.equal(run?.status, 'queued');
        assert.equal(run?.id, 12);
    });

    it('shows validating copy after save draft', () => {
        assert.equal(
            layoutSavedDraftMessage(null, { validating: true }),
            'Draft saved · Validating layout…',
        );
    });
});
