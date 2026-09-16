import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    buildEmployeeProfileUpdatePayload,
    employeeProfileUpdateRequiresPostSpoof,
    resolveEmployeeProfileSaveVisit,
} from './employee-profile-form-state.ts';

function profileData(
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        employee_no: '1034',
        name: 'Mohammed Rabil T',
        branch_id: '',
        department_id: '6',
        position_id: '12',
        rank_id: '',
        project_id: '',
        client_id: '',
        personal_email: 'rabil@example.com',
        work_email: 'rabil@example.com',
        phone: '+971 56 976 9023',
        phone_home_country: '',
        emergency_contact: '',
        emergency_phone: '',
        nearest_airport: '',
        address: '',
        date_of_birth: '2000-12-02',
        hire_date: '2026-02-02',
        place_of_birth: 'India',
        gender_id: '1',
        religion_id: '1',
        visa_type_id: '1',
        company_visa_type_id: '',
        nationality_id: '9',
        marital_status: '',
        spouse_name: '',
        passport_number: 'C4614899',
        emirates_id: '784200083327914',
        salary_payment_method: 'bank_transfer',
        approval_location_ids: [],
        sssa_option_ids: [],
        image: null,
        remove_image: false,
        ...overrides,
    };
}

describe('employee profile photo upload save contract', () => {
    it('requires post spoofing when a photo file is staged', () => {
        const image = new File(['photo'], 'profile.png', {
            type: 'image/png',
        });

        assert.equal(employeeProfileUpdateRequiresPostSpoof(image), true);
        assert.deepEqual(resolveEmployeeProfileSaveVisit(image), {
            httpMethod: 'post',
            forceFormData: true,
        });
    });

    it('uses put without formdata for non-file profile saves', () => {
        assert.equal(employeeProfileUpdateRequiresPostSpoof(null), false);
        assert.deepEqual(resolveEmployeeProfileSaveVisit(null), {
            httpMethod: 'put',
            forceFormData: false,
        });
    });

    it('builds a multipart payload with _method put for staged photos', () => {
        const image = new File(['photo'], 'profile.png', {
            type: 'image/png',
        });
        const payload = buildEmployeeProfileUpdatePayload(
            profileData({ image }),
        );

        assert.equal(payload._method, 'put');
        assert.equal(payload.image, image);
        assert.equal(payload.name, 'Mohammed Rabil T');
    });

    it('does not spoof put for remove-image saves without a staged file', () => {
        const payload = buildEmployeeProfileUpdatePayload(
            profileData({ remove_image: true }),
        );

        assert.equal('_method' in payload, false);
        assert.equal(payload.remove_image, true);
        assert.equal('image' in payload, false);
    });
});
