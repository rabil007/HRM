import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    layoutIssuePlacementIds,
    layoutReadinessSectionCopy,
    layoutValidateButtonLabel,
    layoutValidationFingerprint,
} from '../templates/lib/layout-validation.ts';
import type { LayoutPreflightResult } from '../templates/lib/layout-validation.ts';
import { combinedPublishIssueLabel } from '../templates/lib/template-workflow.ts';

const sampleResult = (
    overrides: Partial<LayoutPreflightResult> = {},
): LayoutPreflightResult => ({
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
        assert.equal(layoutValidateButtonLabel('stale'), 'Validation required');
    });
});

describe('layoutReadinessSectionCopy', () => {
    it('keeps layout status out of a second toolbar label', () => {
        assert.deepEqual(layoutReadinessSectionCopy('valid'), {
            kind: 'ok',
            summary: 'No issues',
        });
        assert.deepEqual(layoutReadinessSectionCopy('invalid', 1), {
            kind: 'issues',
            summary: '1 layout issue',
        });
        assert.deepEqual(layoutReadinessSectionCopy('idle'), {
            kind: 'pending',
            summary: 'Validation required',
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
    });
});
