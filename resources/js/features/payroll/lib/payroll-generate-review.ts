import type { CrewPayrollGenerationPreview } from '../types';

export function payrollGenerateReviewCanConfirm(
    preview: CrewPayrollGenerationPreview | null,
): boolean {
    if (preview === null) {
        return false;
    }

    return preview.blocking_count === 0 && preview.ready_count > 0;
}
