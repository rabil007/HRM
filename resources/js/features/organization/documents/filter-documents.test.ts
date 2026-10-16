import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { filterDocuments, matchesFileSearch } from './filter-documents.ts';
import type { DocumentBrowseItem } from './types';

function makeDoc(
    overrides: Partial<DocumentBrowseItem> = {},
): DocumentBrowseItem {
    return {
        id: 1,
        document_name: 'Passport',
        document_type: 'Passport',
        file_url: '/files/1',
        uploaded_at: '2026-10-15T21:30:00.000Z',
        uploaded_by: 'Admin',
        mime_type: 'application/pdf',
        can_preview: true,
        status: 'active',
        expiry_date: '2030-01-15',
        issue_date: '2020-01-15',
        document_number: 'P123',
        size_bytes: 1000,
        expiry_status: null,
        remaining_days: null,
        expiry_label: '',
        ...overrides,
    };
}

describe('document file search date consistency', () => {
    it('matches company-local upload day across Dubai midnight', () => {
        const doc = makeDoc();
        const options = { timeZone: 'Asia/Dubai', dateFormat: 'd-m-Y' };

        assert.equal(matchesFileSearch(doc, '16-10-2026', options), true);
        assert.equal(matchesFileSearch(doc, '15-10-2026', options), false);
        assert.deepEqual(
            filterDocuments([doc], '16-10-2026', options).map((row) => row.id),
            [1],
        );
    });

    it('still matches date-only issue and expiry values', () => {
        const doc = makeDoc();
        const options = { timeZone: 'Asia/Dubai', dateFormat: 'd-m-Y' };

        assert.equal(matchesFileSearch(doc, '15-01-2020', options), true);
        assert.equal(matchesFileSearch(doc, '15-01-2030', options), true);
        assert.equal(matchesFileSearch(doc, 'passport', options), true);
    });
});
