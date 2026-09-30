import assert from 'node:assert/strict';
import test from 'node:test';
import {
    applyAiFieldsWithoutOverwrite,
    applyManualDraftPatch,
    clearAiOwnedDraftMetadata,
    clearAiOwnedDraftsMetadata,
    confidenceLabel,
    documentAiContextKey,
    documentTypeMismatch,
    idleDocumentAiReview,
    shouldClearAiOwnedOnDraftCountChange,
} from './document-ai-review.ts';

const file = { name: 'passport.pdf', size: 100, lastModified: 1 } as File;
const draft = {
    id: 'one',
    file,
    document_type_id: '1',
    title: 'Passport',
    document_number: '',
    issue_date: '',
    expiry_date: '',
    notes: '',
    ai_filled_fields: [],
};

test('AI fills blank fields without overwriting existing values', () => {
    const result = applyAiFieldsWithoutOverwrite(
        { ...draft, document_number: 'USER' },
        {
            document_number: { value: 'AI', confidence: 0.9 },
            expiry_date: { value: '2030-01-01', confidence: 0.8 },
        },
    );
    assert.equal(result.document_number, 'USER');
    assert.equal(result.expiry_date, '2030-01-01');
    assert.deepEqual(result.ai_filled_fields, ['expiry_date']);
});

test('manual edit clears its AI marker', () => {
    assert.deepEqual(
        applyManualDraftPatch(
            { ...draft, ai_filled_fields: ['document_number'] },
            { document_number: 'EDITED' },
        ).ai_filled_fields,
        [],
    );
});

test('employee switch clears AI-owned values but keeps manual edits', () => {
    const aiOwned = clearAiOwnedDraftMetadata({
        ...draft,
        document_number: 'P123',
        expiry_date: '2030-01-01',
        ai_filled_fields: ['document_number', 'expiry_date'],
    });
    assert.equal(aiOwned.document_number, '');
    assert.equal(aiOwned.expiry_date, '');
    assert.deepEqual(aiOwned.ai_filled_fields, []);

    const manual = clearAiOwnedDraftMetadata(
        applyManualDraftPatch(
            {
                ...draft,
                document_number: 'P123',
                ai_filled_fields: ['document_number'],
            },
            { document_number: 'P456' },
        ),
    );
    assert.equal(manual.document_number, 'P456');
    assert.deepEqual(manual.ai_filled_fields, []);
});

test('bulk to single transition clears AI-owned values but keeps manual edits', () => {
    assert.equal(shouldClearAiOwnedOnDraftCountChange(2, 1), true);
    assert.equal(shouldClearAiOwnedOnDraftCountChange(1, 2), true);
    assert.equal(shouldClearAiOwnedOnDraftCountChange(2, 3), false);
    assert.equal(shouldClearAiOwnedOnDraftCountChange(1, 1), false);

    const bulkFilled = {
        ...draft,
        document_number: 'P123',
        issue_date: '2020-01-01',
        expiry_date: '2030-01-01',
        ai_filled_fields: ['document_number', 'issue_date', 'expiry_date'],
    };
    const manuallyEdited = applyManualDraftPatch(bulkFilled, {
        document_number: 'P456',
    });

    const cleared = clearAiOwnedDraftsMetadata([manuallyEdited])[0];
    assert.equal(cleared.document_number, 'P456');
    assert.equal(cleared.issue_date, '');
    assert.equal(cleared.expiry_date, '');
    assert.deepEqual(cleared.ai_filled_fields, []);
});

test('after bulk to single clear, single-file review can start from idle', () => {
    const review = idleDocumentAiReview();
    assert.equal(review.status, 'idle');
    assert.equal(review.contextKey, null);
    assert.deepEqual(review.fields, {});
});

test('confidence categories use stable thresholds', () => {
    assert.equal(confidenceLabel(0.85), 'High');
    assert.equal(confidenceLabel(0.6), 'Medium');
    assert.equal(confidenceLabel(0.59), 'Low');
});

test('document mismatch is deterministic and unknown is ignored', () => {
    const types = [{ id: 1, title: 'Passport' }];
    assert.match(
        documentTypeMismatch('emirates_id', '1', types) ?? '',
        /Emirates ID/,
    );
    assert.equal(documentTypeMismatch('unknown', '1', types), null);
});

test('employee and file identity form the extraction context', () => {
    assert.notEqual(
        documentAiContextKey(1, draft),
        documentAiContextKey(2, draft),
    );
    assert.notEqual(
        documentAiContextKey(1, draft),
        documentAiContextKey(1, { ...draft, id: 'two' }),
    );
});
