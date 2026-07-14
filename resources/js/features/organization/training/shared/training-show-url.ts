import type { TrainingShowBackContext } from '@/features/organization/training/types';
import { show as trainingShow } from '@/routes/organization/employees/training';

export function buildTrainingShowUrl(
    employeeId: number,
    trainingId: number,
    back: TrainingShowBackContext = { from: 'profile' },
): string {
    const query: Record<string, string> = {
        from: back.from,
    };

    if (back.from === 'index') {
        if (back.expiry && back.expiry !== 'all') {
            query.expiry = back.expiry;
        }

        if (back.search?.trim()) {
            query.search = back.search.trim();
        }

        if (back.issue_date?.trim()) {
            query.issue_date = back.issue_date.trim();
        }

        if (back.course_id?.trim()) {
            query.course_id = back.course_id.trim();
        }

        if (back.institute?.trim()) {
            query.institute = back.institute.trim();
        }

        if (back.country_id?.trim()) {
            query.country_id = back.country_id.trim();
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
    }

    return trainingShow.url(
        { employee: employeeId, training: trainingId },
        { query },
    );
}

export type { TrainingShowBackContext };
