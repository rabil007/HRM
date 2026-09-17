export const SEND_CONFIRM_PREVIEW_ERROR =
    'Recipient count could not be refreshed. Please try again before sending.';

export type AnnouncementAudience = { type: string; id: number | null };

export type SendConfirmSnapshot = {
    recipientCount: number;
    channels: string[];
};

export type SendConfirmPreviewResult =
    | { kind: 'success'; snapshot: SendConfirmSnapshot }
    | { kind: 'failure' }
    | { kind: 'stale' };

export function audiencesForRequest(
    audiences: AnnouncementAudience[],
    allEmployees: { id: number }[],
): AnnouncementAudience[] {
    if (audiences.some((audience) => audience.type === 'all_employees')) {
        return [{ type: 'all_employees', id: null }];
    }

    const employeeIds = audiences
        .filter((audience) => audience.type === 'employee')
        .map((audience) => audience.id)
        .filter((id): id is number => id !== null);
    const otherAudiences = audiences.filter(
        (audience) => audience.type !== 'employee',
    );

    if (
        otherAudiences.length === 0 &&
        allEmployees.length > 0 &&
        employeeIds.length === allEmployees.length &&
        allEmployees.every((employee) => employeeIds.includes(employee.id))
    ) {
        return [{ type: 'all_employees', id: null }];
    }

    return audiences;
}

export function buildSendConfirmSnapshot(
    preview: { selected_employees: number },
    channels: string[],
): SendConfirmSnapshot {
    return {
        recipientCount: preview.selected_employees,
        channels: [...channels],
    };
}

export function canStartSendConfirmPreview(options: {
    loading: boolean;
    dialogOpen: boolean;
    formProcessing: boolean;
}): boolean {
    return !options.loading && !options.dialogOpen && !options.formProcessing;
}

export function shouldApplyPreviewRequestResult(
    requestId: number,
    latestRequestId: number,
): boolean {
    return requestId === latestRequestId;
}

export function resolveSendConfirmPreviewResponse(
    requestId: number,
    latestRequestId: number,
    preview: { selected_employees: number } | null,
    channels: string[],
    failed: boolean,
): SendConfirmPreviewResult {
    if (!shouldApplyPreviewRequestResult(requestId, latestRequestId)) {
        return { kind: 'stale' };
    }

    if (failed || preview === null) {
        return { kind: 'failure' };
    }

    return {
        kind: 'success',
        snapshot: buildSendConfirmSnapshot(preview, channels),
    };
}

export function recipientCountForSendConfirmDialog(
    snapshot: SendConfirmSnapshot | null,
): number | null {
    return snapshot?.recipientCount ?? null;
}

export function channelsForSendConfirmDialog(
    snapshot: SendConfirmSnapshot | null,
): string[] | null {
    return snapshot?.channels ?? null;
}

export function shouldSubmitSendNow(options: {
    formProcessing: boolean;
    alreadySubmitting: boolean;
}): boolean {
    return !options.formProcessing && !options.alreadySubmitting;
}

export function shouldOpenSendConfirmDialog(
    result: SendConfirmPreviewResult,
): result is Extract<SendConfirmPreviewResult, { kind: 'success' }> {
    return result.kind === 'success';
}
