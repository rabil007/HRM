export type CreateSubmitRoute = 'single' | 'bulk';

export function isBulkCreateMode(crewRowCount: number): boolean {
    return crewRowCount >= 2;
}

export function shouldShowSaveDraft(crewRowCount: number): boolean {
    return crewRowCount === 1;
}

export function resolveCreateSubmitRoute(
    crewRowCount: number,
): CreateSubmitRoute {
    return crewRowCount >= 2 ? 'bulk' : 'single';
}

export function bulkStartButtonLabel(readyCount: number): string {
    if (readyCount === 1) {
        return 'Start 1 Assignment';
    }

    return `Start ${readyCount} Assignments`;
}
