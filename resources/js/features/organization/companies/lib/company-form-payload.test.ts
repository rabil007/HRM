import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CompanyFormData } from '../types.ts';
import { buildCompanyFormPayload } from './company-form-payload.ts';

function formData(overrides: Partial<CompanyFormData> = {}): CompanyFormData {
    return {
        logo: null,
        remove_logo: false,
        name: 'Acme Solutions',
        industry: 'Technology',
        company_size: '1-50',
        registration_number: '',
        tax_id: '',
        city: 'Dubai',
        address: '',
        phone: '',
        country_id: 1,
        email: '',
        website: '',
        currency_id: 1,
        timezone: 'Asia/Dubai',
        payroll_cycle: 'monthly',
        working_days: [1, 2, 3, 4, 5],
        wps_agent_code: '',
        wps_mol_uid: '',
        wps_employer_iban: '',
        status: 'active',
        ...overrides,
    };
}

describe('buildCompanyFormPayload', () => {
    it('omits a null logo and spoofs put so Laravel can parse multipart updates', () => {
        const payload = buildCompanyFormPayload(formData(), true);

        assert.equal(payload.name, 'Acme Solutions');
        assert.equal(payload._method, 'put');
        assert.equal('logo' in payload, false);
    });

    it('keeps an uploaded logo and does not spoof create posts', () => {
        const logo = new File(['logo'], 'logo.png', { type: 'image/png' });
        const payload = buildCompanyFormPayload(formData({ logo }), false);

        assert.equal(payload.logo, logo);
        assert.equal('_method' in payload, false);
    });
});
