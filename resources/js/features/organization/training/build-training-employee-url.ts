import { employee } from '@/routes/organization/training';
import type { TrainingEmployeeBackContext } from '@/features/organization/training/types';

export function buildTrainingEmployeeUrl(
    employeeId: number,
    back: TrainingEmployeeBackContext = { from: 'index' },
): string {
    const query: Record<string, string> = {
        from: back.from,
    };

    if (back.search?.trim()) {
        query.search = back.search.trim();
    }

    if (back.expiry && back.expiry !== 'all') {
        query.expiry = back.expiry;
    }

    if (back.branch_id?.trim()) {
        query.branch_id = back.branch_id.trim();
    }

    if (back.department_id?.trim()) {
        query.department_id = back.department_id.trim();
    }

    if (back.page && back.page > 1) {
        query.page = String(back.page);
    }

    return employee.url({ employee: employeeId }, { query });
}
