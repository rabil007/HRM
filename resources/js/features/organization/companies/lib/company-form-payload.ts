import type { CompanyFormData } from '../types';

/**
 * Build the company create/update payload for Inertia FormData submits.
 *
 * Production PHP does not populate multipart bodies on real HTTP PUT, so updates
 * that may include a logo must POST with `_method=put` (method spoofing).
 */
export function buildCompanyFormPayload(
    data: CompanyFormData,
    spoofPut: boolean,
): Record<string, unknown> {
    const payload: Record<string, unknown> = {
        remove_logo: data.remove_logo,
        name: data.name,
        industry: data.industry,
        company_size: data.company_size,
        registration_number: data.registration_number,
        tax_id: data.tax_id,
        city: data.city,
        address: data.address,
        phone: data.phone,
        country_id: data.country_id,
        email: data.email,
        website: data.website,
        currency_id: data.currency_id,
        timezone: data.timezone,
        payroll_cycle: data.payroll_cycle,
        working_days: data.working_days,
        wps_agent_code: data.wps_agent_code,
        wps_mol_uid: data.wps_mol_uid,
        wps_employer_iban: data.wps_employer_iban,
        status: data.status,
    };

    if (data.logo instanceof File) {
        payload.logo = data.logo;
    }

    if (spoofPut) {
        payload._method = 'put';
    }

    return payload;
}
