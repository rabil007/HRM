/** Crew synced from Crew Deployments — currently on vessel. */
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

/** Vacant relief slot — dashed border, muted fill. */
export const vacantBarSurfaceClass =
    'border border-dashed border-muted-foreground/30 bg-muted/20';

export const vacantBarAvatarClass =
    'bg-muted-foreground/10 text-muted-foreground/50';

type AssignmentStyleInput = {
    employee_id: number | null;
    is_deployed: boolean;
};

export function barSurfaceClass(bar: AssignmentStyleInput): string {
    if (bar.employee_id === null) {
        return vacantBarSurfaceClass;
    }

    return bar.is_deployed
        ? deployedBarSurfaceClass
        : plannedReliefBarSurfaceClass;
}

export function barAvatarClass(bar: AssignmentStyleInput): string {
    if (bar.employee_id === null) {
        return vacantBarAvatarClass;
    }

    return bar.is_deployed
        ? deployedBarAvatarClass
        : plannedReliefBarAvatarClass;
}

export function barResizeHandleClass(bar: AssignmentStyleInput): string {
    if (bar.employee_id === null) {
        return plannedReliefBarResizeHandleClass;
    }

    return bar.is_deployed
        ? deployedBarResizeHandleClass
        : plannedReliefBarResizeHandleClass;
}
