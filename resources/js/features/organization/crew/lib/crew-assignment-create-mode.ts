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
 * Vacant Planning handoff may Save Draft; named Planning uses Start Mobilisation.
 */
export function shouldRenderSaveDraftButton(options: {
    canCreate: boolean;
    crewRowCount: number;
}): boolean {
    return options.canCreate && shouldShowSaveDraft(options.crewRowCount);
}

/**
 * Normal create supports Save Draft and Start Assignment only.
 * Future plans belong in Crew Planning (Phase 3). Save as Planned is not offered.
 */
export function resolveCreateFooterActions(options: {
    canCreate: boolean;
    canStart: boolean;
    crewRowCount: number;
    bulkMode: boolean;
    planningActiveAssignmentConflict: boolean;
}): {
    showStart: boolean;
    showDraft: boolean;
} {
    return {
        showStart:
            options.canStart && !options.planningActiveAssignmentConflict,
        showDraft: shouldRenderSaveDraftButton({
            canCreate: options.canCreate,
            crewRowCount: options.crewRowCount,
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

/**
 * Readiness/conflict context employee:
 * - named Planning → authoritative planning employee
 * - vacant Planning → selected form employee
 * - manual create → selected form employee
 */
export function resolveCreateEffectiveEmployeeId(
    planningEmployeeId: number | null,
    crewEmployeeId: number | null,
): number | null {
    return planningEmployeeId !== null ? planningEmployeeId : crewEmployeeId;
}
