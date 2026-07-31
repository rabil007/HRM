import type {
    CrewPayrollRow,
    CrewTimesheetSegment,
    MovementMasterOption,
    MovementMasterOptions,
} from '../types';

export type MovementCategoryGroup = 'standby' | 'onsite';

export const STANDBY_CATEGORIES = [
    'sign_on_standby',
    'sign_off_standby',
] as const;
export const ONSITE_CATEGORIES = ['onsite'] as const;

export function categoryGroupCategories(
    group: MovementCategoryGroup,
): string[] {
    return group === 'standby'
        ? (STANDBY_CATEGORIES as unknown as string[])
        : (ONSITE_CATEGORIES as unknown as string[]);
}

export function defaultCategoryForGroup(group: MovementCategoryGroup): string {
    return group === 'standby' ? 'sign_on_standby' : 'onsite';
}

export type MovementPeriodDraftSegment = {
    key: string;
    pay_category: string;
    vessel_id: number | null;
    client_id: number | null;
    rank_id: number | null;
    from_date: string;
    to_date: string;
    remarks: string;
};

export type ResolvedMovementAssignment = {
    vessel_id: number | null;
    client_id: number | null;
    rank_id: number | null;
};

export type AssignmentSummaryField = {
    label: string;
    value: string;
    assigned: boolean;
};

const NOT_ASSIGNED = 'Not assigned';

export function inclusiveMovementDays(from: string, to: string): number | null {
    if (!from || !to || to < from) {
        return null;
    }

    const start = new Date(`${from}T00:00:00`);
    const end = new Date(`${to}T00:00:00`);
    const diff = Math.round((end.getTime() - start.getTime()) / 86_400_000);

    return diff + 1;
}

export function masterOptionName(
    options: MovementMasterOption[],
    id: number | null,
): string | null {
    if (id === null) {
        return null;
    }

    return options.find((option) => option.id === id)?.name ?? null;
}

export function formatAssignmentFieldValue(
    name: string | null | undefined,
): string {
    if (name === null || name === undefined || name.trim() === '') {
        return NOT_ASSIGNED;
    }

    return name;
}

export function buildAssignmentSummaryFields(
    segment: Pick<
        MovementPeriodDraftSegment,
        'vessel_id' | 'client_id' | 'rank_id'
    >,
    masterOptions: MovementMasterOptions,
): AssignmentSummaryField[] {
    return [
        {
            label: 'Vessel',
            value: formatAssignmentFieldValue(
                masterOptionName(masterOptions.vessels, segment.vessel_id),
            ),
            assigned: segment.vessel_id !== null,
        },
        {
            label: 'Client',
            value: formatAssignmentFieldValue(
                masterOptionName(masterOptions.clients, segment.client_id),
            ),
            assigned: segment.client_id !== null,
        },
        {
            label: 'Rank',
            value: formatAssignmentFieldValue(
                masterOptionName(masterOptions.ranks, segment.rank_id),
            ),
            assigned: segment.rank_id !== null,
        },
    ];
}

export function resolveDefaultAssignment(
    drafts: MovementPeriodDraftSegment[],
    timesheet: CrewPayrollRow['timesheet'],
): ResolvedMovementAssignment {
    for (let index = drafts.length - 1; index >= 0; index -= 1) {
        const draft = drafts[index];

        if (
            draft &&
            (draft.vessel_id !== null ||
                draft.client_id !== null ||
                draft.rank_id !== null)
        ) {
            return {
                vessel_id: draft.vessel_id,
                client_id: draft.client_id,
                rank_id: draft.rank_id,
            };
        }
    }

    const segments = timesheet?.segments ?? [];

    for (let index = segments.length - 1; index >= 0; index -= 1) {
        const segment = segments[index];

        if (
            segment &&
            (segment.vessel_id !== null ||
                segment.client_id !== null ||
                segment.rank_id !== null)
        ) {
            return {
                vessel_id: segment.vessel_id,
                client_id: segment.client_id,
                rank_id: segment.rank_id,
            };
        }
    }

    return {
        vessel_id: null,
        client_id: null,
        rank_id: null,
    };
}

export function createEmptyMovementPeriodDraft(
    key: string,
    assignment: ResolvedMovementAssignment = {
        vessel_id: null,
        client_id: null,
        rank_id: null,
    },
    defaultCategory = 'onsite',
): MovementPeriodDraftSegment {
    return {
        key,
        pay_category: defaultCategory,
        vessel_id: assignment.vessel_id,
        client_id: assignment.client_id,
        rank_id: assignment.rank_id,
        from_date: '',
        to_date: '',
        remarks: '',
    };
}

export function segmentDraftsFromTimesheet(
    timesheet: CrewPayrollRow['timesheet'],
    group?: MovementCategoryGroup,
): MovementPeriodDraftSegment[] {
    const visibleCategories = group
        ? categoryGroupCategories(group)
        : undefined;

    const existing = timesheet?.segments ?? [];

    if (existing.length > 0) {
        const filtered = visibleCategories
            ? existing.filter((s) =>
                  visibleCategories.includes(s.pay_category ?? ''),
              )
            : existing;

        if (filtered.length > 0) {
            return filtered.map((segment, index) =>
                draftFromExistingSegment(segment, index),
            );
        }

        if (visibleCategories) {
            const defaultCat = visibleCategories[0] ?? 'onsite';

            return [
                createEmptyMovementPeriodDraft(
                    `new-${Date.now()}`,
                    undefined,
                    defaultCat,
                ),
            ];
        }

        return existing.map((segment, index) =>
            draftFromExistingSegment(segment, index),
        );
    }

    const drafts: MovementPeriodDraftSegment[] = [];

    const maybePush = (
        category: string,
        from: string | null | undefined,
        to: string | null | undefined,
    ) => {
        if (!from && !to) {
            return;
        }

        if (visibleCategories && !visibleCategories.includes(category)) {
            return;
        }

        drafts.push({
            key: `legacy-${category}`,
            pay_category: category,
            vessel_id: null,
            client_id: null,
            rank_id: null,
            from_date: from ?? '',
            to_date: to ?? '',
            remarks: '',
        });
    };

    maybePush(
        'sign_on_standby',
        timesheet?.sign_on_standby_from,
        timesheet?.sign_on_standby_to,
    );
    maybePush('onsite', timesheet?.onsite_from, timesheet?.onsite_to);
    maybePush(
        'sign_off_standby',
        timesheet?.sign_off_standby_from,
        timesheet?.sign_off_standby_to,
    );

    if (drafts.length === 0) {
        const defaultCat = group ? defaultCategoryForGroup(group) : 'onsite';

        drafts.push(
            createEmptyMovementPeriodDraft(
                `new-${Date.now()}`,
                undefined,
                defaultCat,
            ),
        );
    }

    return drafts;
}

/**
 * Returns segments that belong to the OTHER group (so they can be preserved
 * when only one group is being edited and saved).
 */
export function hiddenGroupSegmentDrafts(
    timesheet: CrewPayrollRow['timesheet'],
    group: MovementCategoryGroup,
): MovementPeriodDraftSegment[] {
    const hiddenCategories: string[] =
        group === 'standby'
            ? (ONSITE_CATEGORIES as unknown as string[])
            : (STANDBY_CATEGORIES as unknown as string[]);

    const existing = timesheet?.segments ?? [];

    return existing
        .filter((s) => hiddenCategories.includes(s.pay_category ?? ''))
        .map((segment, index) => draftFromExistingSegment(segment, index));
}

export function draftFromExistingSegment(
    segment: CrewTimesheetSegment,
    index: number,
): MovementPeriodDraftSegment {
    return {
        key: `existing-${segment.id}-${index}`,
        pay_category: segment.pay_category ?? 'onsite',
        vessel_id: segment.vessel_id,
        client_id: segment.client_id,
        rank_id: segment.rank_id,
        from_date: segment.from_date ?? '',
        to_date: segment.to_date ?? '',
        remarks: segment.remarks ?? '',
    };
}

export function isAssignmentEditorOpen(
    openKeys: ReadonlySet<string>,
    segmentKey: string,
): boolean {
    return openKeys.has(segmentKey);
}

export function toggleAssignmentEditor(
    openKeys: ReadonlySet<string>,
    segmentKey: string,
    open: boolean,
): Set<string> {
    const next = new Set(openKeys);

    if (open) {
        next.add(segmentKey);
    } else {
        next.delete(segmentKey);
    }

    return next;
}
