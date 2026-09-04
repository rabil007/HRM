export type LayoutPreflightIssue = {
    code: string;
    severity: string;
    placement_id: string | null;
    field_key: string | null;
    field_label: string | null;
    page: number | null;
    message: string;
    test_value?: string | null;
};

export type LayoutPreflightResult = {
    valid: boolean;
    mode: 'sample' | 'employee';
    validated_with: {
        mode: 'sample' | 'employee';
        employee_id?: number;
        employee_name?: string;
        employee_no?: string | null;
    };
    effective_font_sizes: Record<string, number | null>;
    issues: LayoutPreflightIssue[];
    fit_count: number;
    overflow_count: number;
};

export type LayoutValidationStatus =
    | 'idle'
    | 'checking'
    | 'valid'
    | 'invalid'
    | 'stale';

export type LayoutValidationState =
    | { status: 'idle' }
    | { status: 'checking' }
    | { status: 'valid'; result: LayoutPreflightResult; fingerprint: string }
    | { status: 'invalid'; result: LayoutPreflightResult; fingerprint: string }
    | { status: 'stale'; previous: LayoutPreflightResult | null };

export function layoutValidationFingerprint(
    versionId: number | null,
    placements: Array<{
        id: string;
        type: string;
        page: number;
        x: number;
        y: number;
        width: number;
        height: number;
        font_size?: number;
        font_weight?: string;
        text_align?: string;
        font_family?: string;
        field?: string;
        text_content?: string;
    }>,
): string {
    return JSON.stringify({
        versionId,
        placements: placements.map((placement) => ({
            id: placement.id,
            type: placement.type,
            page: placement.page,
            x: placement.x,
            y: placement.y,
            width: placement.width,
            height: placement.height,
            font_size: placement.font_size ?? 12,
            font_weight: placement.font_weight ?? 'normal',
            text_align: placement.text_align ?? 'left',
            font_family: placement.font_family ?? 'sans',
            field: placement.field ?? null,
            text_content: placement.text_content ?? null,
        })),
    });
}

export function layoutIssuePlacementIds(
    result: LayoutPreflightResult | null,
): Set<string> {
    const ids = new Set<string>();

    if (!result) {
        return ids;
    }

    for (const issue of result.issues) {
        if (issue.code === 'LAYOUT_OVERFLOW' && issue.placement_id) {
            ids.add(issue.placement_id);
        }
    }

    return ids;
}

export function layoutValidateButtonLabel(
    status: LayoutValidationStatus,
    issueCount = 0,
): string {
    if (status === 'checking') {
        return 'Validating…';
    }

    if (status === 'valid') {
        return 'Layout valid';
    }

    if (status === 'invalid') {
        return issueCount === 1
            ? '1 layout issue'
            : `${issueCount} layout issues`;
    }

    if (status === 'stale') {
        return 'Validation required';
    }

    return 'Validate template';
}

export function layoutReadinessSectionCopy(
    status: LayoutValidationStatus,
    issueCount = 0,
): { kind: 'ok' | 'pending' | 'checking' | 'issues'; summary: string } {
    if (status === 'checking') {
        return { kind: 'checking', summary: 'Validating layout…' };
    }

    if (status === 'valid') {
        return { kind: 'ok', summary: 'No issues' };
    }

    if (status === 'invalid') {
        return {
            kind: 'issues',
            summary:
                issueCount === 1
                    ? '1 layout issue'
                    : `${issueCount} layout issues`,
        };
    }

    return { kind: 'pending', summary: 'Validation required' };
}

export function issueTestValue(
    result: LayoutPreflightResult | null,
    placementId: string,
): string | null {
    const issue = result?.issues.find(
        (item) => item.placement_id === placementId,
    );

    if (!issue) {
        return null;
    }

    return typeof issue.test_value === 'string' ? issue.test_value : null;
}
