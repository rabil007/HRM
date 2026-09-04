export function isGenerationRunActive(
    status: string | null | undefined,
): boolean {
    return status === 'queued' || status === 'running';
}

export function shouldPollBulkDocuments(
    generationActive: boolean,
    emailActive: boolean,
): boolean {
    return generationActive || emailActive;
}

export function generationHasIssues(run: {
    status: string;
    failed_count: number;
}): boolean {
    return run.status === 'completed' && run.failed_count > 0;
}

export function generationDisplayName(run: {
    template_name?: string | null;
}): string {
    return run.template_name?.trim() || 'documents';
}

export function rosterGenerationBadge(employee: {
    document: { id: number } | null;
    generation_run_status?: string | null;
}): {
    kind:
        | 'queued'
        | 'generating'
        | 'generated'
        | 'skipped'
        | 'failed'
        | 'missing';
    label: string;
} {
    const status = employee.generation_run_status;

    if (status === 'processing') {
        return { kind: 'generating', label: 'Generating' };
    }

    if (status === 'pending') {
        return { kind: 'queued', label: 'Queued' };
    }

    if (status === 'failed' && employee.document === null) {
        return { kind: 'failed', label: 'Failed' };
    }

    if (status === 'skipped' && employee.document === null) {
        return { kind: 'skipped', label: 'Skipped' };
    }

    if (employee.document !== null) {
        return { kind: 'generated', label: 'Generated' };
    }

    return { kind: 'missing', label: 'Missing' };
}

export function generationCompletionToast(run: {
    status: string;
    template_name?: string | null;
    generated_count: number;
    skipped_count: number;
    failed_count: number;
    total_targeted: number;
}): { type: 'success' | 'warning' | 'error'; title: string; body: string } {
    const name = generationDisplayName(run);

    if (run.status === 'failed') {
        return {
            type: 'error',
            title: 'Document generation failed',
            body: `${name} could not be completed.`,
        };
    }

    if (generationHasIssues(run)) {
        return {
            type: 'warning',
            title: 'Document generation completed with issues',
            body: `${run.generated_count} generated, ${run.skipped_count} skipped, ${run.failed_count} failed.`,
        };
    }

    return {
        type: 'success',
        title: 'Document generation completed',
        body: `${run.generated_count} ${name} documents are ready.`,
    };
}
