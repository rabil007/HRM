import type { PlanningKind } from '../types';

/** Crew synced from Crew Assignments — currently on vessel. */
export const deployedBarSurfaceClass =
    'border border-emerald-500/55 bg-emerald-500/25 dark:border-emerald-400/50 dark:bg-emerald-500/30';

export const deployedBarAvatarClass =
    'bg-emerald-500/30 text-emerald-800 dark:bg-emerald-400/25 dark:text-emerald-200';

export const deployedBarResizeHandleClass =
    'hover:bg-emerald-500/35 dark:hover:bg-emerald-400/30';

/** Manually planned relief crew — successor after deployed crew leaves. */
export const plannedReliefBarSurfaceClass =
    'border border-sky-500/55 bg-sky-500/25 dark:border-sky-400/50 dark:bg-sky-500/30';

export const plannedReliefBarAvatarClass =
    'bg-sky-500/30 text-sky-800 dark:bg-sky-400/25 dark:text-sky-200';

export const plannedReliefBarResizeHandleClass =
    'hover:bg-sky-500/35 dark:hover:bg-sky-400/30';

/** Planned crew with no assignment created yet and not a relief assignment. */
export const plannedBarSurfaceClass =
    'border border-indigo-500/55 bg-indigo-500/25 dark:border-indigo-400/50 dark:bg-indigo-500/30';

export const plannedBarAvatarClass =
    'bg-indigo-500/30 text-indigo-800 dark:bg-indigo-400/25 dark:text-indigo-200';

export const plannedBarResizeHandleClass =
    'hover:bg-indigo-500/35 dark:hover:bg-indigo-400/30';

/** Vacant relief slot — dashed border, muted fill. */
export const vacantBarSurfaceClass =
    'border border-dashed border-muted-foreground/30 bg-muted/20';

export const vacantBarAvatarClass =
    'bg-muted-foreground/10 text-muted-foreground/50';

type AssignmentStyleInput = {
    employee_id: number | null;
    is_assigned: boolean;
    is_open_ended?: boolean;
    planning_kind?: PlanningKind;
    relieves_crew_assignment_id?: number | null;
};

export function resolveBarKind(bar: AssignmentStyleInput): PlanningKind {
    if (bar.planning_kind) {
        return bar.planning_kind;
    }

    if (bar.employee_id === null) {
        return 'vacant_slot';
    }

    if (bar.is_assigned) {
        return 'assignment_created';
    }

    if (
        bar.relieves_crew_assignment_id !== null &&
        bar.relieves_crew_assignment_id !== undefined
    ) {
        return 'planned_relief';
    }

    return 'planned';
}

export function barSurfaceClass(bar: AssignmentStyleInput): string {
    let surface: string;
    const kind = resolveBarKind(bar);

    switch (kind) {
        case 'vacant_slot':
            surface = vacantBarSurfaceClass;
            break;
        case 'assignment_created':
            surface = deployedBarSurfaceClass;
            break;
        case 'planned_relief':
            surface = plannedReliefBarSurfaceClass;
            break;
        case 'planned':
        default:
            surface = plannedBarSurfaceClass;
            break;
    }

    if (bar.is_open_ended) {
        return `${surface} border-r-2 border-r-dashed pr-1`;
    }

    return surface;
}

export function barAvatarClass(bar: AssignmentStyleInput): string {
    const kind = resolveBarKind(bar);

    switch (kind) {
        case 'vacant_slot':
            return vacantBarAvatarClass;
        case 'assignment_created':
            return deployedBarAvatarClass;
        case 'planned_relief':
            return plannedReliefBarAvatarClass;
        case 'planned':
        default:
            return plannedBarAvatarClass;
    }
}

export function barResizeHandleClass(bar: AssignmentStyleInput): string {
    const kind = resolveBarKind(bar);

    switch (kind) {
        case 'vacant_slot':
            return plannedReliefBarResizeHandleClass;
        case 'assignment_created':
            return deployedBarResizeHandleClass;
        case 'planned_relief':
            return plannedReliefBarResizeHandleClass;
        case 'planned':
        default:
            return plannedBarResizeHandleClass;
    }
}
