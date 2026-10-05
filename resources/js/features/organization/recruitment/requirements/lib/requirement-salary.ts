import type { PositionOption } from '@/types/recruitment';
import type { FormPositionLineInput } from '../types';

function formatAmount(val: number | string): string {
    const num = typeof val === 'number' ? val : Number(val);

    if (Number.isNaN(num)) {
        return '0.00';
    }

    return num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function hasValue(val: number | string | null | undefined): boolean {
    return val !== null && val !== undefined && val !== '';
}

/**
 * Formats a salary range with currency.
 *
 * Examples:
 * - AED 4,000.00 – 6,000.00
 * - AED 5,000.00
 * - Not specified
 */
export function formatSalaryRange(
    min?: number | string | null,
    max?: number | string | null,
    currencyCode?: string | null,
): string {
    const hasMin = hasValue(min);
    const hasMax = hasValue(max);

    if (!hasMin && !hasMax) {
        return 'Not specified';
    }

    const currency =
        currencyCode && currencyCode.trim() ? currencyCode.trim() : 'AED';

    if (hasMin && hasMax) {
        const numMin = typeof min === 'number' ? min : Number(min);
        const numMax = typeof max === 'number' ? max : Number(max);

        const formattedMin = formatAmount(min!);
        const formattedMax = formatAmount(max!);

        if (
            !Number.isNaN(numMin) &&
            !Number.isNaN(numMax) &&
            numMin === numMax
        ) {
            return `${currency} ${formattedMin}`;
        }

        return `${currency} ${formattedMin} – ${formattedMax}`;
    }

    if (hasMin) {
        return `${currency} ${formatAmount(min!)}`;
    }

    return `${currency} ${formatAmount(max!)}`;
}

/**
 * Compares two salary values for equality, considering null/undefined/'' as equivalent.
 */
function salaryValuesEqual(
    a: number | string | null | undefined,
    b: number | string | null | undefined,
): boolean {
    const emptyA = !hasValue(a);
    const emptyB = !hasValue(b);

    if (emptyA && emptyB) {
        return true;
    }

    if (emptyA !== emptyB) {
        return false;
    }

    const numA = typeof a === 'number' ? a : Number(a);
    const numB = typeof b === 'number' ? b : Number(b);

    if (!Number.isNaN(numA) && !Number.isNaN(numB)) {
        return numA === numB;
    }

    return String(a).trim() === String(b).trim();
}

/**
 * Checks whether the line's salary matches the position defaults.
 */
export function isSalaryAtPositionDefault(
    line: FormPositionLineInput,
    position?: PositionOption | null,
): boolean {
    if (!position) {
        return false;
    }

    const posMin = position.min_salary;
    const posMax = position.max_salary;

    // If position has no salary set, it's at default only if line also has no salary
    if (!hasValue(posMin) && !hasValue(posMax)) {
        return !hasValue(line.salary_min) && !hasValue(line.salary_max);
    }

    return (
        salaryValuesEqual(line.salary_min, posMin) &&
        salaryValuesEqual(line.salary_max, posMax)
    );
}

/**
 * Checks whether the line has custom/edited salary different from the position defaults.
 */
export function isSalaryEditedFromPosition(
    line: FormPositionLineInput,
    position?: PositionOption | null,
): boolean {
    if (!position) {
        return false;
    }

    // If line has no salary and position has no salary, not edited
    if (
        !hasValue(line.salary_min) &&
        !hasValue(line.salary_max) &&
        !hasValue(position.min_salary) &&
        !hasValue(position.max_salary)
    ) {
        return false;
    }

    return !isSalaryAtPositionDefault(line, position);
}

/**
 * Resolves default salary values when a position is selected or reset.
 */
export function resolveDefaultSalaryForPosition(
    position?: PositionOption | null,
): {
    salary_min: string | number | null;
    salary_max: string | number | null;
} {
    if (!position) {
        return { salary_min: null, salary_max: null };
    }

    return {
        salary_min: hasValue(position.min_salary) ? position.min_salary! : null,
        salary_max: hasValue(position.max_salary) ? position.max_salary! : null,
    };
}

/**
 * Validates salary range client-side.
 */
export function validateSalaryRange(
    min?: number | string | null,
    max?: number | string | null,
    required: boolean = false,
): { minError?: string; maxError?: string } {
    const hasMin = hasValue(min);
    const hasMax = hasValue(max);

    if (required) {
        if (!hasMin && !hasMax) {
            return {
                minError: 'Minimum salary is required before submitting.',
                maxError: 'Maximum salary is required before submitting.',
            };
        }

        if (!hasMin) {
            return {
                minError: 'Minimum salary is required before submitting.',
            };
        }

        if (!hasMax) {
            return {
                maxError: 'Maximum salary is required before submitting.',
            };
        }
    }

    const numMin = hasMin ? Number(min) : null;
    const numMax = hasMax ? Number(max) : null;

    if (numMin !== null && (Number.isNaN(numMin) || numMin < 0)) {
        return { minError: 'Minimum salary must be a non-negative number.' };
    }

    if (numMax !== null && (Number.isNaN(numMax) || numMax < 0)) {
        return { maxError: 'Maximum salary must be a non-negative number.' };
    }

    if (numMin !== null && numMax !== null && numMax < numMin) {
        return {
            maxError:
                'Maximum salary must be greater than or equal to minimum salary.',
        };
    }

    return {};
}
