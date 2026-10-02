import assert from 'node:assert/strict';
import test from 'node:test';
import {
    DOCUMENT_AI_SUPPORTED_TYPE_LABELS,
    applyAiFieldsWithoutOverwrite,
    applyManualDraftPatch,
    clearAiOwnedDraftMetadata,
    clearAiOwnedDraftsMetadata,
    confidenceLabel,
    documentAiContextKey,
    documentTypeMismatch,
    idleDocumentAiReview,
    resolveDocumentTypeIdFromAiClassification,
    resolveDocumentTypeIdFromDetectedCategory,
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

test('AI auto-selects an unambiguous matching document type', () => {
    const blankType = { ...draft, document_type_id: '' };
    const result = applyAiFieldsWithoutOverwrite(
        blankType,
        {
            document_number: { value: '784-2000-8332791-4', confidence: 0.99 },
        },
        {
            classification: {
                documentType: 'emirates_id',
                documentSubtype: null,
                detectedLabel: 'Emirates ID',
            },
            documentTypes: [
                { id: 10, title: 'Passport' },
                { id: 11, title: 'Emirates ID' },
            ],
        },
    );

    assert.equal(result.document_type_id, '11');
    assert.equal(result.document_number, '784-2000-8332791-4');
    assert.deepEqual(result.ai_filled_fields, [
        'document_number',
        'document_type_id',
    ]);
});

test('AI does not overwrite a manually selected document type', () => {
    const result = applyAiFieldsWithoutOverwrite(
        { ...draft, document_type_id: '10' },
        {},
        {
            classification: {
                documentType: 'emirates_id',
                documentSubtype: null,
                detectedLabel: 'Emirates ID',
            },
            documentTypes: [
                { id: 10, title: 'Passport' },
                { id: 11, title: 'Emirates ID' },
            ],
        },
    );

    assert.equal(result.document_type_id, '10');
    assert.equal(result.ai_filled_fields.includes('document_type_id'), false);
});

test('deterministic resolver maps passport ordinary to exact Passport title', () => {
    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'passport',
                documentSubtype: 'ordinary',
                detectedLabel: 'Ordinary Passport',
            },
            [
                { id: 1, title: 'Passport' },
                { id: 2, title: 'Seaman Passport' },
            ],
        ),
        '1',
    );
    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'passport',
                documentSubtype: 'ordinary',
                detectedLabel: 'Ordinary Passport',
            },
            [{ id: 2, title: 'Seaman Passport' }],
        ),
        null,
    );
});

test('deterministic resolver covers key document families', () => {
    const types = [
        { id: 1, title: 'Diplomatic Passport' },
        { id: 2, title: 'CDC' },
        { id: 3, title: 'Seaman Book' },
        { id: 4, title: 'Residence Visa' },
        { id: 5, title: 'Visit Visa' },
        { id: 6, title: 'Labour Card' },
        { id: 7, title: 'Driving License' },
        { id: 8, title: 'HSE Passport' },
    ];

    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'passport',
                documentSubtype: 'diplomatic',
                detectedLabel: null,
            },
            types,
        ),
        '1',
    );
    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'seafarer_document',
                documentSubtype: 'cdc',
                detectedLabel: null,
            },
            types,
        ),
        '2',
    );
    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'seafarer_document',
                documentSubtype: 'seaman_book',
                detectedLabel: null,
            },
            types,
        ),
        '3',
    );
    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'uae_visa',
                documentSubtype: 'residence',
                detectedLabel: null,
            },
            types,
        ),
        '4',
    );
    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'uae_visa',
                documentSubtype: 'visit',
                detectedLabel: null,
            },
            types,
        ),
        '5',
    );
    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'labour_card',
                documentSubtype: null,
                detectedLabel: null,
            },
            types,
        ),
        '6',
    );
    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'driving_license',
                documentSubtype: null,
                detectedLabel: null,
            },
            types,
        ),
        '7',
    );
    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'hse_passport',
                documentSubtype: null,
                detectedLabel: null,
            },
            types,
        ),
        '8',
    );
});

test('insurance stays manual when multiple company types could match', () => {
    assert.equal(
        resolveDocumentTypeIdFromAiClassification(
            {
                documentType: 'insurance_document',
                documentSubtype: 'card',
                detectedLabel: 'Insurance Card',
            },
            [
                { id: 1, title: 'Medical Insurance Card' },
                { id: 2, title: 'Life Insurance Card' },
                { id: 3, title: 'Crew Insurance Card' },
            ],
        ),
        null,
    );
});

test('legacy category resolver still maps emirates id exactly', () => {
    assert.equal(
        resolveDocumentTypeIdFromDetectedCategory('emirates_id', [
            { id: 11, title: 'Emirates ID' },
            { id: 12, title: 'Emirates ID Copy' },
        ]),
        '11',
    );
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
        document_type_id: '11',
        ai_filled_fields: [
            'document_number',
            'expiry_date',
            'document_type_id',
        ],
    });
    assert.equal(aiOwned.document_number, '');
    assert.equal(aiOwned.expiry_date, '');
    assert.equal(aiOwned.document_type_id, '');
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
    assert.equal(shouldClearAiOwnedOnDraftCountChange(1, 2), false);
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
        documentTypeMismatch(
            {
                detectedDocumentType: 'emirates_id',
                detectedDocumentSubtype: null,
                detectedLabel: 'Emirates ID',
            },
            '1',
            types,
        ) ?? '',
        /Emirates ID/,
    );
    assert.equal(
        documentTypeMismatch(
            {
                detectedDocumentType: 'unknown',
                detectedDocumentSubtype: null,
                detectedLabel: null,
            },
            '1',
            types,
        ),
        null,
    );
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

test('supported document labels list matches product copy', () => {
    assert.deepEqual(DOCUMENT_AI_SUPPORTED_TYPE_LABELS, [
        'Passport',
        'Emirates ID',
        'Labour Card',
        'Seaman Book / CDC',
        'Driving License',
        'Visit Visa',
        'Residence Visa',
        'CICPA',
        'HSE Passport',
        'Insurance',
    ]);
});
