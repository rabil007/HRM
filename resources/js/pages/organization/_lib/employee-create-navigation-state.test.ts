import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    isFreshEmployeeCreatePage,
    resolveEmployeeProfilePreserveState,
} from './employee-profile-persisted-state.ts';

/**
 * Navigation-state contract for employee create lifecycle.
 *
 * There is no browser harness in this repo; these tests lock the Inertia /
 * React-key decisions that determine whether failed saves keep form values
 * and successful finalize clears them.
 */

export function resolveEmployeeCreatePageKey(options: {
    mode: 'create' | 'edit';
    employeeId: number | null | undefined;
    selectedProfileTemplateId: number | null | undefined;
    updatedAt?: string | null;
}): string {
    if (options.mode === 'create') {
        // Must stay stable across null → provisional id so ensure/failed-save
        // does not remount and wipe typed fields.
        return `create-${options.selectedProfileTemplateId ?? 'none'}`;
    }

    return `${options.employeeId}-${options.updatedAt}`;
}

export function shouldPreserveEmployeeProfileStateAfterResponse(options: {
    hasValidationErrors: boolean;
}): boolean {
    const preserveState = resolveEmployeeProfilePreserveState();

    if (preserveState === 'errors') {
        return options.hasValidationErrors;
    }

    return Boolean(preserveState);
}

describe('employee create navigation state', () => {
    it('keeps the create page key stable when ensure assigns a provisional id', () => {
        const beforeEnsure = resolveEmployeeCreatePageKey({
            mode: 'create',
            employeeId: null,
            selectedProfileTemplateId: 7,
        });
        const afterEnsure = resolveEmployeeCreatePageKey({
            mode: 'create',
            employeeId: 99,
            selectedProfileTemplateId: 7,
        });

        assert.equal(beforeEnsure, afterEnsure);
        assert.equal(beforeEnsure, 'create-7');
    });

    it('keeps the create page key stable across failed-save prop updates with the same template', () => {
        const beforeSave = resolveEmployeeCreatePageKey({
            mode: 'create',
            employeeId: null,
            selectedProfileTemplateId: null,
        });
        const afterValidationRedirect = resolveEmployeeCreatePageKey({
            mode: 'create',
            employeeId: 42,
            selectedProfileTemplateId: null,
        });

        assert.equal(beforeSave, afterValidationRedirect);
        assert.equal(
            shouldPreserveEmployeeProfileStateAfterResponse({
                hasValidationErrors: true,
            }),
            true,
            'validation errors must preserve Inertia/React form state',
        );
    });

    it('does not preserve state on successful finalize so a blank create remounts', () => {
        assert.equal(
            shouldPreserveEmployeeProfileStateAfterResponse({
                hasValidationErrors: false,
            }),
            false,
        );
        assert.equal(
            isFreshEmployeeCreatePage({
                isCreateMode: true,
                employeeId: null,
            }),
            true,
        );
    });

    it('changes edit page key when updated_at changes after a successful save', () => {
        const before = resolveEmployeeCreatePageKey({
            mode: 'edit',
            employeeId: 10,
            selectedProfileTemplateId: null,
            updatedAt: '2026-10-08T10:00:00.000000Z',
        });
        const after = resolveEmployeeCreatePageKey({
            mode: 'edit',
            employeeId: 10,
            selectedProfileTemplateId: null,
            updatedAt: '2026-10-08T10:01:00.000000Z',
        });

        assert.notEqual(before, after);
        assert.equal(
            shouldPreserveEmployeeProfileStateAfterResponse({
                hasValidationErrors: false,
            }),
            false,
            'successful edit visits remount from server-confirmed props',
        );
    });

    it('remounts create when the selected profile template changes', () => {
        const before = resolveEmployeeCreatePageKey({
            mode: 'create',
            employeeId: 15,
            selectedProfileTemplateId: null,
        });
        const after = resolveEmployeeCreatePageKey({
            mode: 'create',
            employeeId: 15,
            selectedProfileTemplateId: 3,
        });

        assert.notEqual(before, after);
    });
});
