import assert from 'node:assert/strict';
import test from 'node:test';
import {
    documentsLibraryActionMessage,
    shouldOpenLibraryUpload,
} from '../index/documents-library-action.ts';

test('library actions explain the next real document step', () => {
    assert.match(documentsLibraryActionMessage('upload') ?? '', /employee/i);
    assert.match(documentsLibraryActionMessage('share') ?? '', /Share links/);
    assert.match(
        documentsLibraryActionMessage('download') ?? '',
        /Download ZIP/,
    );
    assert.equal(documentsLibraryActionMessage(null), null);
});

test('upload intent only opens the upload workflow for the matching action', () => {
    assert.equal(
        shouldOpenLibraryUpload(
            '/organization/documents/employees/12?action=upload',
        ),
        true,
    );
    assert.equal(
        shouldOpenLibraryUpload(
            '/organization/documents/employees/12?action=share',
        ),
        false,
    );
    assert.equal(
        shouldOpenLibraryUpload('/organization/documents/employees/12'),
        false,
    );
});
