import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    importPreviewEmptyRowsMessage,
    rowMatchesImportPreviewFilter,
    toggleImportPreviewFilter,
} from './import-preview-row-filter.ts';

describe('import preview row filter', () => {
    const updateRow = { action: 'update' as const, errors: [] };
    const createRow = { action: 'create' as const, errors: [] };
    const skipRow = { action: 'skip' as const, errors: [] };
    const errorRow = {
        action: 'skip' as const,
        errors: [{ message: 'Missing type' }],
    };

    it('matches rows by action, validity, and importable status', () => {
        assert.equal(rowMatchesImportPreviewFilter(updateRow, 'all'), true);
        assert.equal(rowMatchesImportPreviewFilter(updateRow, 'updates'), true);
        assert.equal(rowMatchesImportPreviewFilter(createRow, 'creates'), true);
        assert.equal(rowMatchesImportPreviewFilter(skipRow, 'skipped'), true);
        assert.equal(rowMatchesImportPreviewFilter(errorRow, 'errors'), true);
        assert.equal(rowMatchesImportPreviewFilter(errorRow, 'invalid'), true);
        assert.equal(rowMatchesImportPreviewFilter(updateRow, 'valid'), true);
        assert.equal(
            rowMatchesImportPreviewFilter(updateRow, 'importable'),
            true,
        );
        assert.equal(
            rowMatchesImportPreviewFilter(errorRow, 'importable'),
            false,
        );
        assert.equal(
            rowMatchesImportPreviewFilter(skipRow, 'importable'),
            false,
        );
        assert.equal(
            rowMatchesImportPreviewFilter(updateRow, 'deletes'),
            false,
        );
    });

    it('toggles an active badge back to all rows', () => {
        assert.equal(toggleImportPreviewFilter('all', 'errors'), 'errors');
        assert.equal(toggleImportPreviewFilter('errors', 'errors'), 'all');
        assert.equal(toggleImportPreviewFilter('errors', 'updates'), 'updates');
        assert.equal(toggleImportPreviewFilter('updates', 'all'), 'all');
    });

    it('explains an empty table when a filter or search is active', () => {
        assert.equal(
            importPreviewEmptyRowsMessage('all', ''),
            'No rows found.',
        );
        assert.equal(
            importPreviewEmptyRowsMessage('errors', ''),
            'No rows match your filters.',
        );
        assert.equal(
            importPreviewEmptyRowsMessage('all', 'zakher'),
            'No rows match your filters.',
        );
    });
});
