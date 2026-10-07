import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    incompleteSubmissionMessages,
    isSubmissionReadinessComplete,
} from './requirement-submission-readiness.ts';

/**
 * Mirrors index/detail submit guards: incomplete readiness blocks the POST.
 */
function shouldPostSubmit(
    readiness:
        | {
              ready: boolean;
              remaining_count: number;
              items: Array<{
                  key: string;
                  label: string;
                  ready: boolean;
                  message: string | null;
              }>;
          }
        | null
        | undefined,
): boolean {
    return isSubmissionReadinessComplete(readiness);
}

describe('requirement submit readiness guards', () => {
    it('blocks incomplete draft submit and exposes missing messages', () => {
        const readiness = {
            ready: false,
            remaining_count: 2,
            items: [
                {
                    key: 'assigned_recruiter',
                    label: 'Assigned recruiter',
                    ready: false,
                    message: 'Assign an approving recruiter.',
                },
                {
                    key: 'salary_line_1',
                    label: 'Salary range for Rigger',
                    ready: false,
                    message: 'Complete a valid salary range for Rigger.',
                },
            ],
        };

        assert.equal(shouldPostSubmit(readiness), false);
        assert.deepEqual(incompleteSubmissionMessages(readiness), [
            'Assign an approving recruiter.',
            'Complete a valid salary range for Rigger.',
        ]);
    });

    it('allows ready draft submit', () => {
        assert.equal(
            shouldPostSubmit({
                ready: true,
                remaining_count: 0,
                items: [],
            }),
            true,
        );
    });
});
