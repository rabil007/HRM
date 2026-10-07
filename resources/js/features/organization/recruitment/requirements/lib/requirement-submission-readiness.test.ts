import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    canShowRequirementSubmitFormAction,
    compactSubmissionAttentionLabel,
    evaluateRequirementFormSubmissionReadiness,
    incompleteSubmissionMessages,
    isSubmissionReadinessComplete,
    readinessToFormFieldErrors,
} from './requirement-submission-readiness.ts';

describe('requirement submission readiness', () => {
    it('marks incomplete drafts with missing recruiter and salary', () => {
        const summary = evaluateRequirementFormSubmissionReadiness({
            clientId: '1',
            requestReceivedDate: '2026-10-01',
            requiredByDate: '2026-10-15',
            assignedTo: '',
            positions: [
                {
                    position_id: '4',
                    required_headcount: 2,
                    salary_min: '',
                    salary_max: '',
                },
            ],
            positionTitles: { '4': 'Rigger' },
        });

        assert.equal(summary.ready, false);
        assert.ok(summary.remaining_count >= 2);

        const messages = incompleteSubmissionMessages(summary);
        assert.ok(
            messages.some((message) =>
                message.includes('Assign an approving recruiter'),
            ),
        );
        assert.ok(
            messages.some((message) =>
                message.includes('salary range for Rigger'),
            ),
        );
    });

    it('marks a complete draft ready for approval', () => {
        const summary = evaluateRequirementFormSubmissionReadiness({
            clientId: '1',
            requestReceivedDate: '2026-10-01',
            requiredByDate: '2026-10-15',
            assignedTo: '9',
            positions: [
                {
                    position_id: '4',
                    required_headcount: 2,
                    salary_min: '5000',
                    salary_max: '8000',
                },
            ],
            positionTitles: { '4': 'Rigger' },
        });

        assert.equal(summary.ready, true);
        assert.equal(summary.remaining_count, 0);
        assert.deepEqual(incompleteSubmissionMessages(summary), []);
    });

    it('rejects self-assignment of the approving recruiter', () => {
        const summary = evaluateRequirementFormSubmissionReadiness({
            clientId: '1',
            requestReceivedDate: '2026-10-01',
            requiredByDate: '2026-10-15',
            assignedTo: '42',
            creatorUserId: 42,
            positions: [
                {
                    position_id: '4',
                    required_headcount: 1,
                    salary_min: '1000',
                    salary_max: '2000',
                },
            ],
        });

        assert.equal(summary.ready, false);
        assert.ok(
            incompleteSubmissionMessages(summary).some((message) =>
                message.includes('Self-approval'),
            ),
        );
        assert.equal(
            summary.items.find((item) => item.key === 'self_approval')?.ready,
            false,
        );
    });

    it('gates submit form actions on submit permission only', () => {
        assert.equal(canShowRequirementSubmitFormAction(true), true);
        assert.equal(canShowRequirementSubmitFormAction(false), false);
    });

    it('treats missing readiness as incomplete for index/detail submit guards', () => {
        assert.equal(isSubmissionReadinessComplete(null), false);
        assert.equal(isSubmissionReadinessComplete(undefined), false);
        assert.equal(
            isSubmissionReadinessComplete({
                ready: true,
                remaining_count: 0,
                items: [],
            }),
            true,
        );
    });

    it('maps readiness failures to form field keys for inline highlighting', () => {
        const summary = evaluateRequirementFormSubmissionReadiness({
            clientId: '',
            requestReceivedDate: '',
            requiredByDate: '',
            assignedTo: '',
            positions: [],
        });

        const errors = readinessToFormFieldErrors(summary, []);

        assert.equal(errors.client_id, 'Select a client.');
        assert.equal(
            errors.request_received_date,
            'Enter the Request Received from Client date.',
        );
        assert.equal(errors.required_by_date, 'Enter the required-by date.');
        assert.equal(errors.assigned_to, 'Assign an approving recruiter.');
        assert.equal(
            errors.positions,
            'At least one active position line is required.',
        );
        assert.match(
            compactSubmissionAttentionLabel(summary.remaining_count),
            /need attention before this requirement can be submitted/,
        );
    });

    it('maps salary readiness failures to the matching position salary field', () => {
        const positions = [
            {
                id: 12,
                position_id: '4',
                required_headcount: 1,
                salary_min: '',
                salary_max: '',
            },
        ];
        const summary = evaluateRequirementFormSubmissionReadiness({
            clientId: '1',
            requestReceivedDate: '2026-10-01',
            requiredByDate: '2026-10-15',
            assignedTo: '9',
            positions,
            positionTitles: { '4': 'Rigger' },
        });

        const errors = readinessToFormFieldErrors(summary, positions);

        assert.equal(
            errors['positions.0.salary_min'],
            'Complete a valid salary range for Rigger.',
        );
    });

    it('keeps submit actions available when permission allows and hidden otherwise', () => {
        assert.equal(canShowRequirementSubmitFormAction(true), true);
        assert.equal(canShowRequirementSubmitFormAction(false), false);
    });

    it('maps missing headcount to the matching position field', () => {
        const positions = [
            {
                position_id: '4',
                required_headcount: 0,
                salary_min: '1000',
                salary_max: '2000',
            },
        ];
        const summary = evaluateRequirementFormSubmissionReadiness({
            clientId: '1',
            requestReceivedDate: '2026-10-01',
            requiredByDate: '2026-10-15',
            assignedTo: '9',
            positions,
        });

        const errors = readinessToFormFieldErrors(summary, positions);

        assert.equal(
            errors['positions.0.required_headcount'],
            'Enter a required headcount of at least 1 for Position 1.',
        );
    });
});
