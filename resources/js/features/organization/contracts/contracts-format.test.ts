import assert from 'node:assert/strict';
import test from 'node:test';
import { contractOfficeSalaryTotal } from './contracts-format.ts';

test('monthly crew total includes EOS exactly once while office total stays unchanged', () => {
    const contract = {
        payroll_category: 'crew',
        salary_structure: 'monthly',
        basic_salary: 15000,
        housing_allowance: 10000,
        transport_allowance: 5000,
        other_allowances: 24125,
        eos_monthly_amount: 875,
    };
    assert.equal(contractOfficeSalaryTotal(contract), 55000);
    assert.equal(
        contractOfficeSalaryTotal({ ...contract, payroll_category: 'office' }),
        54125,
    );
    assert.equal(
        contractOfficeSalaryTotal({ ...contract, eos_monthly_amount: null }),
        54125,
    );
});
