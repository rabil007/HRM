import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PositionOption } from '@/types/recruitment';
import {
    formatSalaryRange,
    isSalaryAtPositionDefault,
    isSalaryEditedFromPosition,
    resolveDefaultSalaryForPosition,
    validateSalaryRange,
} from './requirement-salary.ts';

describe('formatSalaryRange', () => {
    it('returns "Not specified" when both min and max are null or empty', () => {
        assert.equal(formatSalaryRange(null, null), 'Not specified');
        assert.equal(formatSalaryRange('', ''), 'Not specified');
        assert.equal(formatSalaryRange(undefined, undefined), 'Not specified');
    });

    it('formats a distinct salary range with currency', () => {
        assert.equal(
            formatSalaryRange(4000, 6000, 'AED'),
            'AED 4,000.00 – 6,000.00',
        );
        assert.equal(
            formatSalaryRange('4000.50', '6000.75', 'USD'),
            'USD 4,000.50 – 6,000.75',
        );
    });

    it('formats a single amount when min and max are equal', () => {
        assert.equal(formatSalaryRange(5000, 5000, 'AED'), 'AED 5,000.00');
        assert.equal(
            formatSalaryRange('5000', '5000.00', 'AED'),
            'AED 5,000.00',
        );
    });

    it('defaults currency to AED if currency code is not provided', () => {
        assert.equal(formatSalaryRange(5000, 5000), 'AED 5,000.00');
        assert.equal(formatSalaryRange(4000, 6000), 'AED 4,000.00 – 6,000.00');
    });

    it('formats single boundary when only one is provided', () => {
        assert.equal(formatSalaryRange(4000, null, 'AED'), 'AED 4,000.00');
        assert.equal(formatSalaryRange(null, 6000, 'AED'), 'AED 6,000.00');
    });
});

describe('isSalaryAtPositionDefault and isSalaryEditedFromPosition', () => {
    const positionWithSalary: PositionOption = {
        id: 1,
        title: 'Master',
        grade: 'A',
        status: 'active',
        min_salary: '10000.00',
        max_salary: '15000.00',
    };

    const positionWithoutSalary: PositionOption = {
        id: 2,
        title: 'Trainee',
        grade: 'D',
        status: 'active',
        min_salary: null,
        max_salary: null,
    };

    it('detects when line matches position defaults', () => {
        assert.equal(
            isSalaryAtPositionDefault(
                {
                    position_id: 1,
                    required_headcount: 1,
                    salary_min: '10000.00',
                    salary_max: '15000.00',
                },
                positionWithSalary,
            ),
            true,
        );

        assert.equal(
            isSalaryAtPositionDefault(
                {
                    position_id: 1,
                    required_headcount: 1,
                    salary_min: 10000,
                    salary_max: 15000,
                },
                positionWithSalary,
            ),
            true,
        );

        assert.equal(
            isSalaryEditedFromPosition(
                {
                    position_id: 1,
                    required_headcount: 1,
                    salary_min: '10000.00',
                    salary_max: '15000.00',
                },
                positionWithSalary,
            ),
            false,
        );
    });

    it('detects when line salary has been edited away from position defaults', () => {
        assert.equal(
            isSalaryAtPositionDefault(
                {
                    position_id: 1,
                    required_headcount: 1,
                    salary_min: '11000.00',
                    salary_max: '15000.00',
                },
                positionWithSalary,
            ),
            false,
        );

        assert.equal(
            isSalaryEditedFromPosition(
                {
                    position_id: 1,
                    required_headcount: 1,
                    salary_min: '11000.00',
                    salary_max: '15000.00',
                },
                positionWithSalary,
            ),
            true,
        );
    });

    it('handles position without salary defaults', () => {
        assert.equal(
            isSalaryAtPositionDefault(
                {
                    position_id: 2,
                    required_headcount: 1,
                    salary_min: '',
                    salary_max: '',
                },
                positionWithoutSalary,
            ),
            true,
        );

        assert.equal(
            isSalaryEditedFromPosition(
                {
                    position_id: 2,
                    required_headcount: 1,
                    salary_min: '5000',
                    salary_max: '6000',
                },
                positionWithoutSalary,
            ),
            true,
        );
    });
});

describe('resolveDefaultSalaryForPosition', () => {
    it('returns min and max salary when position has salaries', () => {
        const result = resolveDefaultSalaryForPosition({
            id: 1,
            title: 'Master',
            grade: 'A',
            status: 'active',
            min_salary: '10000.00',
            max_salary: '15000.00',
        });

        assert.deepEqual(result, {
            salary_min: '10000.00',
            salary_max: '15000.00',
        });
    });

    it('returns nulls when position has null or empty salaries', () => {
        const result = resolveDefaultSalaryForPosition({
            id: 2,
            title: 'Cadet',
            grade: 'D',
            status: 'active',
            min_salary: null,
            max_salary: null,
        });

        assert.deepEqual(result, {
            salary_min: null,
            salary_max: null,
        });
    });

    it('returns nulls when position is null or undefined', () => {
        assert.deepEqual(resolveDefaultSalaryForPosition(null), {
            salary_min: null,
            salary_max: null,
        });
        assert.deepEqual(resolveDefaultSalaryForPosition(undefined), {
            salary_min: null,
            salary_max: null,
        });
    });
});

describe('validateSalaryRange', () => {
    it('passes valid salary range', () => {
        assert.deepEqual(validateSalaryRange(5000, 8000), {});
        assert.deepEqual(validateSalaryRange('5000.00', '8000.00'), {});
        assert.deepEqual(validateSalaryRange(5000, 5000), {});
    });

    it('flags required missing salaries when required is true', () => {
        const bothMissing = validateSalaryRange(null, null, true);
        assert.ok(bothMissing.minError);
        assert.ok(bothMissing.maxError);

        const minMissing = validateSalaryRange(null, 5000, true);
        assert.ok(minMissing.minError);
        assert.equal(minMissing.maxError, undefined);

        const maxMissing = validateSalaryRange(5000, null, true);
        assert.equal(maxMissing.minError, undefined);
        assert.ok(maxMissing.maxError);
    });

    it('allows missing salaries when required is false (e.g. draft saving)', () => {
        assert.deepEqual(validateSalaryRange(null, null, false), {});
        assert.deepEqual(validateSalaryRange('', '', false), {});
    });

    it('rejects negative salary values', () => {
        const resMin = validateSalaryRange(-100, 5000);
        assert.ok(resMin.minError);

        const resMax = validateSalaryRange(1000, -500);
        assert.ok(resMax.maxError);
    });

    it('rejects salary_max < salary_min', () => {
        const result = validateSalaryRange(8000, 5000);
        assert.ok(result.maxError);
        assert.match(result.maxError!, /greater than or equal/i);
    });
});
