import { normalizeDecimalFieldValue } from '@/pages/organization/_lib/normalize-decimal-field-value';
import { omitHiddenTemplateRecordFields } from '@/pages/organization/_lib/template-field-visibility';
import type {
    EmployeeContractDetails,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';

export type ContractFormData = {
    payroll_category: string;
    salary_structure: string;
    company_visa_type_id: string;
    start_date: string;
    end_date: string;
    labor_contract_id: string;
    status: string;
    basic_salary: string;
    housing_allowance: string;
    transport_allowance: string;
    other_allowances: string;
    eos_monthly_amount: string;
    supplementary_allowance: string;
    site_allowance: string;
    note: string;
};

function moneyToFormValue(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}

export function emptyContractFormData(options?: {
    companyVisaTypeId?: number | null;
}): ContractFormData {
    return {
        payroll_category: 'office',
        salary_structure: 'monthly',
        company_visa_type_id: options?.companyVisaTypeId
            ? String(options.companyVisaTypeId)
            : '',
        start_date: '',
        end_date: '',
        labor_contract_id: '',
        status: 'active',
        basic_salary: '',
        housing_allowance: '',
        transport_allowance: '',
        other_allowances: '',
        eos_monthly_amount: '',
        supplementary_allowance: '',
        site_allowance: '',
        note: '',
    };
}

export function hydrateContractFormData(
    row: EmployeeContractDetails,
): ContractFormData {
    return {
        payroll_category: row.payroll_category ?? 'office',
        salary_structure:
            row.salary_structure ??
            (row.payroll_category === 'crew' ? 'daily' : 'monthly'),
        company_visa_type_id: row.company_visa_type_id
            ? String(row.company_visa_type_id)
            : '',
        start_date: row.start_date ?? '',
        end_date: row.end_date ?? '',
        labor_contract_id: row.labor_contract_id ?? '',
        status: row.status ?? 'active',
        basic_salary: moneyToFormValue(row.basic_salary),
        housing_allowance: moneyToFormValue(row.housing_allowance),
        transport_allowance: moneyToFormValue(row.transport_allowance),
        other_allowances: moneyToFormValue(row.other_allowances),
        eos_monthly_amount: moneyToFormValue(row.eos_monthly_amount),
        supplementary_allowance: moneyToFormValue(row.supplementary_allowance),
        site_allowance: moneyToFormValue(row.site_allowance),
        note: row.note ?? '',
    };
}

export function isDailyCrewContractRow(
    row: Pick<EmployeeContractDetails, 'payroll_category' | 'salary_structure'>,
): boolean {
    return (
        row.payroll_category === 'crew' &&
        (row.salary_structure ?? 'daily') !== 'monthly'
    );
}

export function isCrewContractForm(data: ContractFormData): boolean {
    return data.payroll_category === 'crew';
}

export function isCrewMonthlyForm(data: ContractFormData): boolean {
    return isCrewContractForm(data) && data.salary_structure === 'monthly';
}

export function isCrewDailyForm(data: ContractFormData): boolean {
    return isCrewContractForm(data) && data.salary_structure !== 'monthly';
}

export function formatContractFormMoney(
    value: number | null | undefined,
): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const numeric = Number(value);

    if (Number.isNaN(numeric)) {
        return String(value);
    }

    return numeric.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function contractRowTotalSalary(row: EmployeeContractDetails): number {
    const components = isDailyCrewContractRow(row)
        ? [row.basic_salary, row.supplementary_allowance, row.site_allowance]
        : [
              row.basic_salary,
              row.housing_allowance,
              row.transport_allowance,
              row.other_allowances,
              row.payroll_category === 'crew' ? row.eos_monthly_amount : 0,
          ];

    return components.reduce<number>(
        (sum, value) => sum + (Number(value) || 0),
        0,
    );
}

export function parseFormMoney(value: string): number {
    const n = parseFloat(value.replace(/,/g, ''));

    return Number.isNaN(n) ? 0 : n;
}

export function contractFormLiveTotal(
    data: ContractFormData,
    options?: { includeEos?: boolean },
): { hasAnyValue: boolean; total: number } {
    const fields = isCrewDailyForm(data)
        ? [data.basic_salary, data.supplementary_allowance, data.site_allowance]
        : [
              data.basic_salary,
              data.housing_allowance,
              data.transport_allowance,
              data.other_allowances,
              options?.includeEos ? data.eos_monthly_amount : '',
          ];

    const hasAnyValue = fields.some((field) => field.trim() !== '');

    const total = fields.reduce((sum, field) => sum + parseFormMoney(field), 0);

    return { hasAnyValue, total };
}

export function buildContractPayload(
    data: ContractFormData,
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
): Record<string, unknown> {
    return omitHiddenTemplateRecordFields(
        {
            payroll_category: data.payroll_category,
            salary_structure:
                data.payroll_category === 'crew'
                    ? data.salary_structure
                    : 'monthly',
            company_visa_type_id:
                data.company_visa_type_id === ''
                    ? null
                    : Number(data.company_visa_type_id),
            start_date: data.start_date,
            end_date: data.end_date === '' ? null : data.end_date,
            labor_contract_id:
                data.labor_contract_id.trim() === ''
                    ? null
                    : data.labor_contract_id.trim(),
            status: data.status,
            basic_salary: normalizeDecimalFieldValue(data.basic_salary),
            housing_allowance: normalizeDecimalFieldValue(
                data.housing_allowance,
            ),
            transport_allowance: normalizeDecimalFieldValue(
                data.transport_allowance,
            ),
            other_allowances: normalizeDecimalFieldValue(data.other_allowances),
            eos_monthly_amount:
                data.payroll_category === 'crew' &&
                data.salary_structure === 'monthly'
                    ? normalizeDecimalFieldValue(data.eos_monthly_amount)
                    : null,
            supplementary_allowance: normalizeDecimalFieldValue(
                data.supplementary_allowance,
            ),
            site_allowance: normalizeDecimalFieldValue(data.site_allowance),
            note: data.note.trim() === '' ? null : data.note.trim(),
        },
        templateFields,
    );
}
