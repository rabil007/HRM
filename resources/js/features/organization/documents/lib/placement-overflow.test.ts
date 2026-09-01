import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    LONG_NAME_OVERFLOW_SAMPLE,
    overflowLevelFromWidth,
    overflowLevelFromWrappedBox,
    overflowPageBanner,
    overflowPreviewText,
    placementOverflowLabel,
    summarizeOverflowLabels,
} from '../templates/lib/placement-overflow.ts';

describe('overflowLevelFromWidth', () => {
    it('is ok when the value already fits', () => {
        assert.equal(overflowLevelFromWidth(80, 160, 12), 'ok');
    });

    it('is shrink when a size at or above 8pt still fits', () => {
        assert.equal(overflowLevelFromWidth(200, 160, 12), 'shrink');
    });

    it('is fail when even 8pt would overflow', () => {
        assert.equal(overflowLevelFromWidth(400, 100, 12), 'fail');
    });
});

describe('overflowLevelFromWrappedBox', () => {
    it('is ok when wrapped lines fit the box height', () => {
        assert.equal(overflowLevelFromWrappedBox(80, 160, 40, 12, 16), 'ok');
    });

    it('is fail when a wrapped value cannot fit at 8pt', () => {
        assert.equal(overflowLevelFromWrappedBox(800, 40, 16, 12, 16), 'fail');
    });
});

describe('overflowPreviewText', () => {
    it('prefers a real employee value', () => {
        assert.equal(
            overflowPreviewText('{{employee_name}}', 'Jane Smith', 'Ali'),
            'Ali',
        );
    });

    it('uses a long name probe when no employee is selected', () => {
        assert.equal(
            overflowPreviewText('{{employee_name}}', 'Jane Smith'),
            LONG_NAME_OVERFLOW_SAMPLE,
        );
    });

    it('uses the catalog sample for non-name fields', () => {
        assert.equal(
            overflowPreviewText('{{employee_no}}', 'EMP-1042'),
            'EMP-1042',
        );
    });
});

describe('overflowPageBanner', () => {
    it('names boxes that cannot fit and ignores shrink-only cases', () => {
        assert.deepEqual(
            overflowPageBanner(['Employee Full Name', 'Employee Full Name']),
            {
                tone: 'fail',
                message:
                    'These boxes are too small for the text: Employee Full Name. Drag them bigger.',
            },
        );
        assert.equal(overflowPageBanner([]), null);
    });

    it('names a single box that cannot fit', () => {
        assert.deepEqual(overflowPageBanner(['Manager Name']), {
            tone: 'fail',
            message:
                'Manager Name is too small for the text. Drag the box bigger.',
        });
    });
});

describe('placementOverflowLabel', () => {
    it('prefers the catalog label for merge fields', () => {
        assert.equal(
            placementOverflowLabel(
                'field',
                '{{employee_name}}',
                'Employee Full Name',
            ),
            'Employee Full Name',
        );
    });

    it('uses the text content for static boxes', () => {
        assert.equal(placementOverflowLabel('text', '15000'), '15000');
        assert.equal(placementOverflowLabel('text', '   '), 'Text box');
    });
});

describe('summarizeOverflowLabels', () => {
    it('dedupes repeated labels', () => {
        assert.equal(
            summarizeOverflowLabels([
                'Employee Full Name',
                'Employee Full Name',
                'Nationality',
            ]),
            'Employee Full Name, Nationality',
        );
    });
});
