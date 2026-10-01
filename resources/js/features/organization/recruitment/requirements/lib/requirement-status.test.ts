import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    REQUIREMENT_STATUS_LABELS,
    isUrgentPriority,
    resolveRequirementDeadlineStyle,
    resolveRequirementStatusLabel,
    resolveRequirementStatusStyle,
} from './requirement-status.ts';

describe('requirement status helpers', () => {
    it('maps every supported status to a label and style', () => {
        for (const status of Object.keys(REQUIREMENT_STATUS_LABELS) as Array<
            keyof typeof REQUIREMENT_STATUS_LABELS
        >) {
            assert.equal(
                resolveRequirementStatusLabel(status),
                REQUIREMENT_STATUS_LABELS[status],
            );
            assert.match(resolveRequirementStatusStyle(status), /border-/);
        }
    });

    it('includes approval workflow statuses', () => {
        assert.equal(
            resolveRequirementStatusLabel('pending_approval'),
            'Pending Approval',
        );
        assert.equal(resolveRequirementStatusLabel('returned'), 'Returned');
        assert.match(
            resolveRequirementStatusStyle('pending_approval'),
            /violet/,
        );
        assert.match(resolveRequirementStatusStyle('returned'), /orange/);
    });

    it('prefers server-provided labels and does not invent urgency', () => {
        assert.equal(resolveRequirementStatusLabel('open', 'Live'), 'Live');
        assert.equal(isUrgentPriority('urgent'), true);
        assert.equal(isUrgentPriority('normal'), false);
        assert.match(resolveRequirementDeadlineStyle('overdue'), /rose/);
    });
});
