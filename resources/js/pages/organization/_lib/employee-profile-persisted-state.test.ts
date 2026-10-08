import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    canEditEmployeeProfile,
    isFreshEmployeeCreatePage,
    mergePersistedEmployeeAfterEnsure,
    resolveEmployeeNumberHeaderState,
    resolveEmployeeProfilePreserveState,
} from './employee-profile-persisted-state.ts';

describe('persisted employee state after ensure', () => {
    it('keeps provisional employee_no from the server and only updates identity fields', () => {
        const merged = mergePersistedEmployeeAfterEnsure(
            {
                id: null,
                name: '',
                employee_no: '',
                updated_at: null,
            } as never,
            {
                id: 42,
                name: 'Captain Ahmed',
                employee_no: 'DRAFT-ABCDEFGH',
            },
        );

        assert.equal(merged.id, 42);
        assert.equal(merged.name, 'Captain Ahmed');
        assert.equal(merged.employee_no, 'DRAFT-ABCDEFGH');
    });

    it('treats typed official numbers as unsaved while persisted value remains draft', () => {
        const persistedEmployeeNo = 'DRAFT-ABCDEFGH';
        const formEmployeeNo = 'EMP-1001';

        assert.notEqual(
            formEmployeeNo,
            persistedEmployeeNo,
            'typed official number must differ from persisted draft baseline',
        );

        const header = resolveEmployeeNumberHeaderState(
            persistedEmployeeNo,
            formEmployeeNo,
        );

        assert.equal(header.displayValue, 'EMP-1001');
        assert.equal(header.hasUnsavedOfficialNumber, true);
        assert.equal(header.isProvisional, false);
    });

    it('shows provisional messaging when no official number has been entered', () => {
        const header = resolveEmployeeNumberHeaderState('DRAFT-ABCDEFGH', '');

        assert.equal(header.isProvisional, true);
        assert.equal(header.displayValue, '');
        assert.match(header.helperMessage ?? '', /Temporary employee ID/i);
    });

    it('allows create-only users to finish draft profiles but not edit official numbers', () => {
        assert.equal(
            canEditEmployeeProfile(['employees.create'], {
                isCreateMode: true,
                persistedEmployeeNo: '',
            }),
            true,
        );

        assert.equal(
            canEditEmployeeProfile(['employees.create'], {
                isCreateMode: false,
                persistedEmployeeNo: 'DRAFT-ABCDEFGH',
            }),
            true,
        );

        assert.equal(
            canEditEmployeeProfile(['employees.create'], {
                isCreateMode: false,
                persistedEmployeeNo: 'EMP-1001',
            }),
            false,
        );
    });

    it('disables preserveState for create-mode saves so blank create remounts cleanly', () => {
        assert.equal(
            resolveEmployeeProfilePreserveState({ isCreateMode: true }),
            false,
        );
        assert.equal(
            resolveEmployeeProfilePreserveState({ isCreateMode: false }),
            true,
        );
    });

    it('detects a fresh create page after successful finalize redirect', () => {
        assert.equal(
            isFreshEmployeeCreatePage({
                isCreateMode: true,
                employeeId: null,
            }),
            true,
        );
        assert.equal(
            isFreshEmployeeCreatePage({
                isCreateMode: true,
                employeeId: 42,
            }),
            false,
        );
        assert.equal(
            isFreshEmployeeCreatePage({
                isCreateMode: false,
                employeeId: null,
            }),
            false,
        );
    });
});
