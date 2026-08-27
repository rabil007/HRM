import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { insertMergeField } from '../templates/lib/insert-merge-field.ts';

describe('insertMergeField', () => {
    it('appends field key with space when no selection is provided', () => {
        const result = insertMergeField('Dear', '{{employee_name}}');
        assert.equal(result.newContent, 'Dear {{employee_name}}');
        assert.equal(result.newCursorPosition, 'Dear {{employee_name}}'.length);
    });

    it('appends without extra space when content already ends with newline', () => {
        const result = insertMergeField('Header:\n', '{{company_name}}');
        assert.equal(result.newContent, 'Header:\n{{company_name}}');
    });

    it('inserts at cursor position', () => {
        const content = 'Dear , welcome!';
        const selection = { start: 5, end: 5 };
        const result = insertMergeField(
            content,
            '{{employee_name}}',
            selection,
        );
        assert.equal(result.newContent, 'Dear {{employee_name}}, welcome!');
        assert.equal(result.newCursorPosition, 5 + '{{employee_name}}'.length);
    });

    it('replaces selected text with merge field', () => {
        const content = 'Dear [Name], welcome!';
        const selection = { start: 5, end: 11 };
        const result = insertMergeField(
            content,
            '{{employee_name}}',
            selection,
        );
        assert.equal(result.newContent, 'Dear {{employee_name}}, welcome!');
    });
});
