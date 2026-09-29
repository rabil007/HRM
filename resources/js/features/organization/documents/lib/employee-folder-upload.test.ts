import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { EmployeeSummary } from '../shared/types.ts';
import {
    resolveEmployeeFolderUploadConfig,
    resolveLibraryUploadConfig,
    resolveUploadDialogHeading,
} from './employee-folder-upload.ts';

const mockEmployee: EmployeeSummary = {
    id: 204,
    name: 'SIYAD',
    employee_no: '204',
    email: 'siyad@example.com',
    phone: '+971501234567',
};

describe('employee-folder upload configuration', () => {
    it('enables Add Document action when canUpload is true and documentsTabVisible is true', () => {
        const config = resolveEmployeeFolderUploadConfig(
            mockEmployee,
            true,
            true,
        );

        assert.equal(config.canShowAddDocument, true);
        assert.equal(config.dialogProps.employeeId, 204);
        assert.equal(config.dialogProps.employeeName, 'SIYAD');
        assert.equal(config.dialogProps.employeeNo, '204');
        assert.equal(config.dialogProps.allowEmployeeSelection, false);
        assert.deepEqual(config.dialogProps.partialReloadKeys, [
            'documents',
            'summary',
        ]);
    });

    it('hides Add Document action when canUpload is true and documentsTabVisible is false', () => {
        const config = resolveEmployeeFolderUploadConfig(
            mockEmployee,
            true,
            false,
        );

        assert.equal(config.canShowAddDocument, false);
        assert.equal(config.dialogProps.allowEmployeeSelection, false);
    });

    it('hides Add Document action when canUpload is false and documentsTabVisible is true', () => {
        const config = resolveEmployeeFolderUploadConfig(
            mockEmployee,
            false,
            true,
        );

        assert.equal(config.canShowAddDocument, false);
        assert.equal(config.dialogProps.allowEmployeeSelection, false);
    });

    it('defaults documentsTabVisible to true when omitted', () => {
        const config = resolveEmployeeFolderUploadConfig(mockEmployee, true);

        assert.equal(config.canShowAddDocument, true);
    });

    it('library mode allows employee selection and has no pre-selected employee', () => {
        const config = resolveLibraryUploadConfig(true);

        assert.equal(config.canShowAddDocument, true);
        assert.equal(config.dialogProps.allowEmployeeSelection, true);
        assert.equal(config.dialogProps.employeeId, null);
    });
});

describe('upload dialog heading wording', () => {
    it('formats fixed employee mode heading with employee number', () => {
        const heading = resolveUploadDialogHeading({
            allowEmployeeSelection: false,
            employeeName: 'SIYAD',
            employeeNo: '204',
        });

        assert.equal(heading.title, 'Add Document');
        assert.equal(
            heading.description,
            'Adding documents for SIYAD (#204). Add files, then complete their details.',
        );
    });

    it('strips redundant leading hash from employee number', () => {
        const heading = resolveUploadDialogHeading({
            allowEmployeeSelection: false,
            employeeName: 'SIYAD',
            employeeNo: '#204',
        });

        assert.equal(heading.title, 'Add Document');
        assert.equal(
            heading.description,
            'Adding documents for SIYAD (#204). Add files, then complete their details.',
        );
    });

    it('formats fixed employee mode heading without employee number', () => {
        const heading = resolveUploadDialogHeading({
            allowEmployeeSelection: false,
            employeeName: 'SIYAD',
            employeeNo: null,
        });

        assert.equal(heading.title, 'Add Document');
        assert.equal(
            heading.description,
            'Adding documents for SIYAD. Add files, then complete their details.',
        );
    });

    it('formats library mode heading before employee selection', () => {
        const heading = resolveUploadDialogHeading({
            allowEmployeeSelection: true,
            selectedEmployee: null,
        });

        assert.equal(heading.title, 'Add Document to Library');
        assert.equal(
            heading.description,
            'Select an active employee, then add and configure files to upload to their document profile.',
        );
    });

    it('formats library mode heading after employee selection', () => {
        const heading = resolveUploadDialogHeading({
            allowEmployeeSelection: true,
            selectedEmployee: {
                name: 'SIYAD',
                employee_no: '204',
            },
        });

        assert.equal(heading.title, 'Add Document to Library');
        assert.equal(
            heading.description,
            'Uploading documents for SIYAD (#204). Select a file on the left, then enter its details on the right.',
        );
    });
});
