export type CreateSubmitRoute = 'single' | 'bulk';

export function isBulkCreateMode(crewRowCount: number): boolean {
    return crewRowCount >= 2;
}

export function shouldShowSaveDraft(crewRowCount: number): boolean {
    return crewRowCount === 1;
}

/**
 * Footer visibility for Save Draft on the unified Create form.
 * Backend still authorizes with crew_operations.assignments.create.
 */
export function shouldRenderSaveDraftButton(options: {
    canCreate: boolean;
    crewRowCount: number;
    fromPlanning: boolean;
}): boolean {
    return (
        options.canCreate &&
        shouldShowSaveDraft(options.crewRowCount) &&
        !options.fromPlanning
    );
}

/**
 * Plan-only users see Save as Planned (and Cancel) — not Draft or Start.
 */
export function resolveCreateFooterActions(options: {
    canCreate: boolean;
    canPlan: boolean;
    canStart: boolean;
    crewRowCount: number;
    fromPlanning: boolean;
    bulkMode: boolean;
    planningActiveAssignmentConflict: boolean;
}): {
    showStart: boolean;
    showPlan: boolean;
    showDraft: boolean;
} {
    return {
        showStart:
            options.canStart && !options.planningActiveAssignmentConflict,
        showPlan: options.canPlan && !options.bulkMode && !options.fromPlanning,
        showDraft: shouldRenderSaveDraftButton({
            canCreate: options.canCreate,
            crewRowCount: options.crewRowCount,
            fromPlanning: options.fromPlanning,
        }),
    };
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

export function resolveCreateEffectiveEmployeeId(
    fromPlanning: boolean,
    planningEmployeeId: number | null,
    crewEmployeeId: number | null,
): number | null {
    return fromPlanning ? planningEmployeeId : crewEmployeeId;
}
