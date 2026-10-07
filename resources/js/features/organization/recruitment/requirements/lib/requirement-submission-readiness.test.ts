import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    evaluateRequirementFormSubmissionReadiness,
    incompleteSubmissionMessages,
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
    });
});
