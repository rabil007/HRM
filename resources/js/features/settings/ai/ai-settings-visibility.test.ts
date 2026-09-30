import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    shouldRenderDocumentAiSection,
    shouldRenderPlatformAiSection,
} from './ai-settings-visibility.ts';

const platformAi = {
    enabled: true,
    provider: 'openai',
    openai: { has_api_key: true, model: 'gpt-test' },
    openrouter: { has_api_key: false, model: '' },
};

const documentAi = {
    mode: 'optional',
    provider_available: true,
    available: true,
    company_name: 'Acme Shipping',
};

describe('AI settings page sections', () => {
    it('renders platform AI only when platform props exist', () => {
        assert.equal(shouldRenderPlatformAiSection(platformAi), true);
        assert.equal(shouldRenderPlatformAiSection(null), false);
    });

    it('renders Document AI only when company settings exist', () => {
        assert.equal(shouldRenderDocumentAiSection(documentAi), true);
        assert.equal(shouldRenderDocumentAiSection(null), false);
    });

    it('keeps company-only managers without platform provider controls', () => {
        assert.equal(shouldRenderPlatformAiSection(null), false);
        assert.equal(shouldRenderDocumentAiSection(documentAi), true);
    });
});
