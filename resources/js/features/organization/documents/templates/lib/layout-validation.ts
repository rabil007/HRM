export type LayoutPreflightStatus = 'valid' | 'invalid' | 'unavailable';

export type LayoutPreflightIssue = {
    code: string;
    severity: string;
    placement_id: string | null;
    field_key: string | null;
    field_label: string | null;
    page: number | null;
    message: string;
    test_value?: string | null;
    reference?: string | null;
};

export type LayoutPreflightResult = {
    status: LayoutPreflightStatus;
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
    reference?: string | null;
};

export type LayoutValidationStatus =
    | 'idle'
    | 'checking'
    | 'valid'
    | 'invalid'
    | 'unavailable'
    | 'stale';

export type LayoutValidationRunStatus =
    | 'queued'
    | 'processing'
    | 'valid'
    | 'invalid'
    | 'unavailable'
    | 'stale';

export type LayoutValidationRun = {
    id: number;
    status: LayoutValidationRunStatus;
    mode: 'sample' | 'employee';
    authoritative: boolean;
    valid: boolean;
    validated_with: LayoutPreflightResult['validated_with'];
    effective_font_sizes: Record<string, number | null>;
    issues: LayoutPreflightIssue[];
    fit_count: number;
    overflow_count: number;
    reference: string | null;
    started_at?: string | null;
    finished_at?: string | null;
};

export type LayoutValidationState =
    | { status: 'idle' }
    | { status: 'checking'; fingerprint: string; runId: number }
    | { status: 'valid'; result: LayoutPreflightResult; fingerprint: string }
    | { status: 'invalid'; result: LayoutPreflightResult; fingerprint: string }
    | {
          status: 'unavailable';
          result: LayoutPreflightResult;
          fingerprint: string;
      }
    | { status: 'stale'; previous: LayoutPreflightResult | null };

export const LAYOUT_VALIDATION_POLL_MS = 1800;

export const LAYOUT_PUBLISH_CODES = {
    invalid: 'TEMPLATE_LAYOUT_INVALID',
    unavailable: 'TEMPLATE_LAYOUT_VALIDATION_UNAVAILABLE',
    pending: 'TEMPLATE_LAYOUT_VALIDATION_PENDING',
    required: 'TEMPLATE_LAYOUT_VALIDATION_REQUIRED',
} as const;

export const LAYOUT_ISSUE_CODES = {
    overflow: 'LAYOUT_OVERFLOW',
    sourceUnavailable: 'TEMPLATE_SOURCE_UNAVAILABLE',
    configurationInvalid: 'TEMPLATE_LAYOUT_CONFIGURATION_INVALID',
    validationUnavailable: 'TEMPLATE_LAYOUT_VALIDATION_UNAVAILABLE',
} as const;

export function normalizeLayoutPreflightResult(
    raw: Partial<LayoutPreflightResult> & {
        issues?: LayoutPreflightIssue[];
        valid?: boolean;
        status?: string;
    },
): LayoutPreflightResult {
    const issues = Array.isArray(raw.issues) ? raw.issues : [];
    const engineFailed = issues.some(
        (issue) => issue.code === LAYOUT_ISSUE_CODES.validationUnavailable,
    );
    const status: LayoutPreflightStatus =
        raw.status === 'valid' ||
        raw.status === 'invalid' ||
        raw.status === 'unavailable'
            ? raw.status
            : engineFailed
              ? 'unavailable'
              : raw.valid
                ? 'valid'
                : 'invalid';

    return {
        status,
        valid: Boolean(raw.valid) && status === 'valid',
        mode: raw.mode === 'employee' ? 'employee' : 'sample',
        validated_with: raw.validated_with ?? { mode: 'sample' },
        effective_font_sizes: raw.effective_font_sizes ?? {},
        issues,
        fit_count: raw.fit_count ?? 0,
        overflow_count: raw.overflow_count ?? 0,
        reference: raw.reference ?? null,
    };
}

export function isTerminalLayoutRunStatus(
    status: string,
): status is Exclude<LayoutValidationRunStatus, 'queued' | 'processing'> {
    return (
        status === 'valid' ||
        status === 'invalid' ||
        status === 'unavailable' ||
        status === 'stale'
    );
}

export function layoutPreflightResultFromRun(
    run: LayoutValidationRun,
): LayoutPreflightResult {
    return normalizeLayoutPreflightResult({
        status:
            run.status === 'valid' ||
            run.status === 'invalid' ||
            run.status === 'unavailable'
                ? run.status
                : run.valid
                  ? 'valid'
                  : 'invalid',
        valid: run.valid,
        mode: run.mode,
        validated_with: run.validated_with,
        effective_font_sizes: run.effective_font_sizes,
        issues: run.issues,
        fit_count: run.fit_count,
        overflow_count: run.overflow_count,
        reference: run.reference,
    });
}

export function layoutValidationStateFromRun(
    run: LayoutValidationRun,
    fingerprint: string,
): LayoutValidationState {
    if (run.status === 'queued' || run.status === 'processing') {
        return { status: 'checking', fingerprint, runId: run.id };
    }

    if (run.status === 'stale') {
        return {
            status: 'stale',
            previous: layoutPreflightResultFromRun(run),
        };
    }

    return layoutValidationStateFromResult(
        layoutPreflightResultFromRun(run),
        fingerprint,
    );
}

export function applyLayoutRunIfCurrent(
    run: LayoutValidationRun,
    requestFingerprint: string,
    currentFingerprint: string,
): LayoutValidationState {
    if (requestFingerprint !== currentFingerprint) {
        return { status: 'stale', previous: null };
    }

    return layoutValidationStateFromRun(run, requestFingerprint);
}

export function parseLayoutValidationRunPayload(
    raw: unknown,
): LayoutValidationRun | null {
    if (typeof raw !== 'object' || raw === null) {
        return null;
    }

    const envelope = raw as { run?: unknown };
    const candidate =
        envelope.run && typeof envelope.run === 'object' ? envelope.run : raw;
    const run = candidate as Partial<LayoutValidationRun>;

    if (typeof run.id !== 'number' || typeof run.status !== 'string') {
        return null;
    }

    return {
        id: run.id,
        status: run.status as LayoutValidationRunStatus,
        mode: run.mode === 'employee' ? 'employee' : 'sample',
        authoritative: Boolean(run.authoritative),
        valid: Boolean(run.valid),
        validated_with: run.validated_with ?? { mode: 'sample' },
        effective_font_sizes: run.effective_font_sizes ?? {},
        issues: Array.isArray(run.issues) ? run.issues : [],
        fit_count: run.fit_count ?? 0,
        overflow_count: run.overflow_count ?? 0,
        reference: run.reference ?? null,
        started_at: run.started_at ?? null,
        finished_at: run.finished_at ?? null,
    };
}

export function layoutValidationStateFromResult(
    result: LayoutPreflightResult,
    fingerprint: string,
): LayoutValidationState {
    if (result.status === 'unavailable') {
        return { status: 'unavailable', result, fingerprint };
    }

    if (result.valid && result.status === 'valid') {
        return { status: 'valid', result, fingerprint };
    }

    return { status: 'invalid', result, fingerprint };
}

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

export function layoutOverflowIssues(
    result: LayoutPreflightResult | null,
): LayoutPreflightIssue[] {
    if (!result) {
        return [];
    }

    return result.issues.filter(
        (issue) =>
            issue.code === LAYOUT_ISSUE_CODES.overflow &&
            Boolean(issue.placement_id),
    );
}

export function layoutIssuePlacementIds(
    result: LayoutPreflightResult | null,
): Set<string> {
    const ids = new Set<string>();

    for (const issue of layoutOverflowIssues(result)) {
        if (issue.placement_id) {
            ids.add(issue.placement_id);
        }
    }

    return ids;
}

export function layoutOverflowIssueCount(
    result: LayoutPreflightResult | null,
): number {
    return layoutOverflowIssues(result).length;
}

export function layoutValidationReference(
    result: LayoutPreflightResult | null,
): string | null {
    if (!result) {
        return null;
    }

    if (typeof result.reference === 'string' && result.reference !== '') {
        return result.reference;
    }

    const issue = result.issues.find(
        (item) => typeof item.reference === 'string' && item.reference !== '',
    );

    return issue?.reference ?? null;
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

    if (status === 'unavailable') {
        return 'Validation unavailable';
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
): {
    kind: 'ok' | 'pending' | 'checking' | 'issues' | 'unavailable';
    summary: string;
    detail: string | null;
} {
    if (status === 'checking') {
        return {
            kind: 'checking',
            summary: 'Validating layout…',
            detail: null,
        };
    }

    if (status === 'valid') {
        return { kind: 'ok', summary: 'No issues', detail: null };
    }

    if (status === 'unavailable') {
        return {
            kind: 'unavailable',
            summary: 'Validation unavailable',
            detail: 'The PDF validation engine could not complete the layout check.',
        };
    }

    if (status === 'invalid') {
        return {
            kind: 'issues',
            summary:
                issueCount === 1
                    ? '1 layout issue'
                    : `${issueCount} layout issues`,
            detail: null,
        };
    }

    return {
        kind: 'pending',
        summary: 'Validation required',
        detail: null,
    };
}

export function layoutPublishBlockMessage(
    result: LayoutPreflightResult | null,
): string {
    if (!result || result.status === 'unavailable') {
        return 'Layout validation could not be completed. Publishing is unavailable until the validation check succeeds.';
    }

    const count = layoutOverflowIssueCount(result);

    if (count === 1) {
        return 'This template has 1 layout issue that must be fixed before publishing.';
    }

    if (count > 1) {
        return `This template has ${count} layout issues that must be fixed before publishing.`;
    }

    return 'This template has layout issues that must be fixed before publishing.';
}

export function layoutSavedDraftMessage(
    result: LayoutPreflightResult | null,
    options?: { validating?: boolean },
): string {
    if (options?.validating) {
        return 'Draft saved · Validating layout…';
    }

    if (!result || result.valid) {
        return 'Draft saved';
    }

    if (result.status === 'unavailable') {
        return 'Draft saved · Validation unavailable';
    }

    const count = layoutOverflowIssueCount(result);

    if (count === 1) {
        return 'Draft saved · 1 layout issue';
    }

    if (count > 1) {
        return `Draft saved · ${count} layout issues`;
    }

    return 'Draft saved';
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
