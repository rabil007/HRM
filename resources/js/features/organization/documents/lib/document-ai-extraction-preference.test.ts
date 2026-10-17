import assert from 'node:assert/strict';
import test from 'node:test';
import {
    documentAiExtractionPreferenceStorageKey,
    isDocumentAiExtractionActive,
    parseDocumentAiExtractionPreference,
    resolveDocumentAiExtractionEnabled,
} from './document-ai-extraction-preference.ts';

test('preference storage key is scoped per user', () => {
    assert.equal(
        documentAiExtractionPreferenceStorageKey(12),
        'oms-hrm:document-ai-extraction:12',
    );
    assert.notEqual(
        documentAiExtractionPreferenceStorageKey(12),
        documentAiExtractionPreferenceStorageKey(99),
    );
});

test('parseDocumentAiExtractionPreference accepts only on/off', () => {
    assert.equal(parseDocumentAiExtractionPreference('on'), 'on');
    assert.equal(parseDocumentAiExtractionPreference('off'), 'off');
    assert.equal(parseDocumentAiExtractionPreference('maybe'), null);
    assert.equal(parseDocumentAiExtractionPreference(null), null);
});

test('unset preference defaults ON for automatic and OFF for optional', () => {
    assert.equal(resolveDocumentAiExtractionEnabled(null, 'automatic'), true);
    assert.equal(resolveDocumentAiExtractionEnabled(null, 'optional'), false);
    assert.equal(resolveDocumentAiExtractionEnabled(null, 'off'), false);
});

test('stored preference overrides company-mode default', () => {
    assert.equal(resolveDocumentAiExtractionEnabled('off', 'automatic'), false);
    assert.equal(resolveDocumentAiExtractionEnabled('on', 'optional'), true);
});

test('AI extraction is inactive unless upload, company, provider, and preference allow it', () => {
    assert.equal(
        isDocumentAiExtractionActive({
            canUpload: true,
            companyMode: 'optional',
            providerAvailable: true,
            userPreferenceEnabled: true,
        }),
        true,
    );
    assert.equal(
        isDocumentAiExtractionActive({
            canUpload: true,
            companyMode: 'optional',
            providerAvailable: true,
            userPreferenceEnabled: false,
        }),
        false,
    );
    assert.equal(
        isDocumentAiExtractionActive({
            canUpload: false,
            companyMode: 'automatic',
            providerAvailable: true,
            userPreferenceEnabled: true,
        }),
        false,
    );
    assert.equal(
        isDocumentAiExtractionActive({
            canUpload: true,
            companyMode: 'off',
            providerAvailable: true,
            userPreferenceEnabled: true,
        }),
        false,
    );
    assert.equal(
        isDocumentAiExtractionActive({
            canUpload: true,
            companyMode: 'optional',
            providerAvailable: false,
            userPreferenceEnabled: true,
        }),
        false,
    );
});

test('preference OFF short-circuits before extract eligibility (measured)', () => {
    const iterations = 50_000;
    let activeHits = 0;
    const started = performance.now();

    for (let index = 0; index < iterations; index += 1) {
        if (
            isDocumentAiExtractionActive({
                canUpload: true,
                companyMode: 'optional',
                providerAvailable: true,
                userPreferenceEnabled: false,
            })
        ) {
            activeHits += 1;
        }
    }

    const elapsedMs = performance.now() - started;

    assert.equal(activeHits, 0);
    // Local gate must stay trivial vs a network extract (ms-scale, not seconds).
    assert.ok(
        elapsedMs < 250,
        `preference-OFF gate: expected ${iterations} checks under 250ms, got ${elapsedMs.toFixed(2)}ms`,
    );
});
