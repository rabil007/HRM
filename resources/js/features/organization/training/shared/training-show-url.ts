import { show as trainingShow } from '@/routes/organization/employees/training';

export function buildTrainingShowUrl(
    employeeId: number,
    trainingId: number,
): string {
    return trainingShow.url({
        employee: employeeId,
        training: trainingId,
    });
}
