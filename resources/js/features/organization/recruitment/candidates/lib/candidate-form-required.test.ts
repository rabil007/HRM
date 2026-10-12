import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

/**
 * Mirrors StoreCandidateRequest / UpdateCandidateRequest contact rules for UX copy.
 */
export function contactRequiresAtLeastOne(
    email: string,
    phone: string,
): boolean {
    return email.trim() === '' && phone.trim() === '';
}

export const ADD_CANDIDATE_UNCONDITIONAL_REQUIRED = [
    'recruitment_requirement_id',
    'recruitment_requirement_line_id',
    'name',
] as const;

export const ADD_CANDIDATE_CONTACT_HINT =
    'Provide at least one: Email or Phone.';

describe('candidate form required UX rules', () => {
    it('marks requirement, position, and name as unconditionally required', () => {
        assert.deepEqual(
            [...ADD_CANDIDATE_UNCONDITIONAL_REQUIRED],
            [
                'recruitment_requirement_id',
                'recruitment_requirement_line_id',
                'name',
            ],
        );
    });

    it('requires at least one contact channel without forcing both', () => {
        assert.equal(contactRequiresAtLeastOne('', ''), true);
        assert.equal(contactRequiresAtLeastOne('a@b.com', ''), false);
        assert.equal(contactRequiresAtLeastOne('', '+971'), false);
        assert.equal(contactRequiresAtLeastOne('a@b.com', '+971'), false);
        assert.match(ADD_CANDIDATE_CONTACT_HINT, /Email or Phone/);
    });
});
