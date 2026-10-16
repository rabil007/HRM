/**
 * Identity used to decide whether an open requirement form should rehydrate.
 * Company-local "today" is intentionally excluded so midnight rollover cannot
 * wipe unsaved draft values while the sheet stays open.
 */
export type RequirementFormInitIdentity = {
    open: boolean;
    requirementId: number | null;
    companyTimezone: string;
};

export function shouldReinitializeRequirementForm(
    previous: RequirementFormInitIdentity | null,
    next: RequirementFormInitIdentity,
): boolean {
    if (!next.open) {
        return false;
    }

    if (previous === null || !previous.open) {
        return true;
    }

    return (
        previous.requirementId !== next.requirementId ||
        previous.companyTimezone !== next.companyTimezone
    );
}
