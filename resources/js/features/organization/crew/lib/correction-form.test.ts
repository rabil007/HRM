import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CorrectablePhase } from '../types.ts';
import {
    CORRECTION_SELECT_OPTIONS,
    editableCorrectionFields,
    initialCorrectionFieldValue,
    initialCorrectionValues,
} from './correction-form.ts';

function trainingPhase(): CorrectablePhase {
    return {
        id: 1,
        phase_code: 'p2b',
        phase_label: 'Training',
        status: 'completed',
        status_label: 'Completed',
        actual_start_at: '2026-03-03T10:00:00Z',
        actual_end_at: '2026-03-05T12:00:00Z',
        remarks: null,
        details: { course_id: 12, course: 'BOSIET', provider: 'ABC Academy' },
        allowed_fields: [
            'actual_start_at',
            'actual_end_at',
            'remarks',
            'details.provider',
            'details.course',
            'details.course_id',
        ],
        has_pending_correction: false,
        current_values: {
            actual_start_at: {
                value: '2026-03-03T10:00:00Z',
                display: '2026-03-03 14:00:00',
            },
            actual_end_at: {
                value: '2026-03-05T12:00:00Z',
                display: '2026-03-05 16:00:00',
            },
            remarks: { value: null, display: null },
            'details.provider': {
                value: 'ABC Academy',
                display: 'ABC Academy',
            },
            'details.course': { value: 'BOSIET', display: 'BOSIET' },
            'details.course_id': { value: 12, display: 'BOSIET' },
        },
    };
}

describe('training correction form', () => {
    it('initializes the Course selector from the ID rather than the display name', () => {
        assert.equal(
            initialCorrectionFieldValue('details.course_id', {
                value: 12,
                display: 'BOSIET',
            }),
            '12',
        );
        assert.equal(CORRECTION_SELECT_OPTIONS['details.course_id'], 'courses');
        assert.equal(CORRECTION_SELECT_OPTIONS['details.provider'], undefined);
        assert.equal(CORRECTION_SELECT_OPTIONS['details.course'], undefined);
    });

    it('omits the duplicate text Course from editable fields and submitted values', () => {
        const phase = trainingPhase();
        assert.deepEqual(editableCorrectionFields(phase), [
            'actual_start_at',
            'actual_end_at',
            'remarks',
            'details.provider',
            'details.course_id',
        ]);
        assert.equal('details.course' in initialCorrectionValues(phase), false);
    });

    for (const [field, value] of [
        ['details.provider', 'XYZ Academy'],
        ['actual_end_at', '2026-03-04T18:00:00'],
    ]) {
        it(`preserves the Course ID when only ${field} changes`, () => {
            const proposedValues = {
                ...initialCorrectionValues(trainingPhase()),
                [field]: value,
            };

            assert.equal(proposedValues[field], value);
            assert.equal(proposedValues['details.course_id'], '12');
            assert.equal('details.course' in proposedValues, false);
        });
    }

    it('submits the selected Course ID without creating a text snapshot', () => {
        const proposedValues = {
            ...initialCorrectionValues(trainingPhase()),
            'details.course_id': '19',
        };

        assert.equal(proposedValues['details.course_id'], '19');
        assert.equal('details.course' in proposedValues, false);
    });

    for (const missingId of [null, undefined]) {
        it(`keeps legacy text Course editable when the Course ID is ${missingId}`, () => {
            const phase = trainingPhase();
            phase.details = { course: 'Internal Vessel Induction' };
            phase.current_values['details.course'] = {
                value: 'Internal Vessel Induction',
                display: 'Internal Vessel Induction',
            };

            if (missingId === null) {
                phase.current_values['details.course_id'] = {
                    value: null,
                    display: null,
                };
            } else {
                delete phase.current_values['details.course_id'];
            }

            const proposedValues = initialCorrectionValues(phase);
            assert.equal(
                proposedValues['details.course'],
                'Internal Vessel Induction',
            );
            assert.equal('details.course_id' in proposedValues, false);
            assert.equal(
                editableCorrectionFields(phase).includes('details.course'),
                true,
            );
        });
    }

    it('does not enable a structured field the backend has not allowed', () => {
        const phase = trainingPhase();
        phase.allowed_fields = ['details.course', 'details.provider'];

        assert.deepEqual(initialCorrectionValues(phase), {
            'details.course': 'BOSIET',
            'details.provider': 'ABC Academy',
        });
    });

    it('preserves company-local dates, assignment IDs, text and empty values', () => {
        const values = initialCorrectionValues(trainingPhase());
        assert.equal(values.actual_start_at, '2026-03-03T14:00:00');
        assert.equal(values.actual_end_at, '2026-03-05T16:00:00');
        assert.equal(values.remarks, '');
        assert.equal(values['details.provider'], 'ABC Academy');

        for (const field of [
            'vessel_id',
            'rank_id',
            'client_id',
            'company_visa_type_id',
        ]) {
            assert.equal(
                initialCorrectionFieldValue(field, {
                    value: 7,
                    display: 'Name',
                }),
                '7',
            );
            assert.equal(
                initialCorrectionFieldValue(field, {
                    value: null,
                    display: null,
                }),
                '',
            );
        }

        assert.equal(
            initialCorrectionFieldValue('details.course_id', {
                value: null,
                display: 'BOSIET',
            }),
            '',
        );
        assert.equal(initialCorrectionFieldValue('remarks', undefined), '');
    });
});
