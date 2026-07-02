import { Loader2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { formatUploadFileSize } from '@/features/organization/documents/upload/upload-draft';
import { cn } from '@/lib/utils';

export type DocumentUploadProgressPhase =
    | 'preparing'
    | 'uploading'
    | 'processing';

export type DocumentUploadProgressState = {
    percentage: number;
    loaded?: number;
    total?: number;
} | null;

const PHASE_COPY: Record<
    DocumentUploadProgressPhase,
    { title: string; description: string }
> = {
    preparing: {
        title: 'Preparing files',
        description: 'Optimizing images before upload…',
    },
    uploading: {
        title: 'Uploading',
        description: 'Sending your files to the server…',
    },
    processing: {
        title: 'Processing on server',
        description:
            'Saving files and optimizing large PDFs. This may take a moment.',
    },
};

function progressDetail(progress: DocumentUploadProgressState): string | null {
    if (!progress) {
        return null;
    }

    if (progress.total && progress.total > 0) {
        return `${formatUploadFileSize(progress.loaded ?? 0)} of ${formatUploadFileSize(progress.total)}`;
    }

    if (progress.percentage > 0) {
        return `${progress.percentage}% uploaded`;
    }

    return null;
}

export function DocumentUploadProgressOverlay({
    open,
    phase,
    progress = null,
    fileLabel,
    className,
}: {
    open: boolean;
    phase: DocumentUploadProgressPhase;
    progress?: DocumentUploadProgressState;
    fileLabel?: string | null;
    className?: string;
}): ReactElement | null {
    if (!open) {
        return null;
    }

    const copy = PHASE_COPY[phase];
    const percentage = Math.min(100, Math.max(0, progress?.percentage ?? 0));
    const showDeterminateBar = phase === 'uploading' && percentage > 0;
    const detail = progressDetail(progress);

    return (
        <div
            className={cn(
                'absolute inset-0 z-50 flex items-center justify-center rounded-lg bg-background/85 p-6 backdrop-blur-sm',
                className,
            )}
            role="status"
            aria-live="polite"
            aria-busy="true"
        >
            <div className="w-full max-w-sm space-y-4 rounded-2xl border border-border bg-card p-5 shadow-lg">
                <div className="flex items-start gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <Loader2
                            className="h-5 w-5 animate-spin"
                            aria-hidden="true"
                        />
                    </div>
                    <div className="min-w-0 space-y-1">
                        <p className="text-sm font-semibold">{copy.title}</p>
                        <p className="text-xs text-muted-foreground">
                            {copy.description}
                        </p>
                        {fileLabel ? (
                            <p className="truncate text-xs font-medium text-foreground/80">
                                {fileLabel}
                            </p>
                        ) : null}
                    </div>
                </div>

                <div className="space-y-2">
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        {showDeterminateBar ? (
                            <div
                                className="h-full rounded-full bg-primary transition-[width] duration-200 ease-out"
                                style={{ width: `${percentage}%` }}
                            />
                        ) : (
                            <div className="h-full w-1/3 animate-pulse rounded-full bg-primary/80" />
                        )}
                    </div>
                    <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                        <span>{detail ?? 'Please wait…'}</span>
                        {showDeterminateBar ? (
                            <span className="tabular-nums">{percentage}%</span>
                        ) : null}
                    </div>
                </div>
            </div>
        </div>
    );
}

export function resolveDocumentUploadPhase({
    isPreparing,
    isUploading,
    progress,
}: {
    isPreparing: boolean;
    isUploading: boolean;
    progress: DocumentUploadProgressState;
}): DocumentUploadProgressPhase | null {
    if (isPreparing) {
        return 'preparing';
    }

    if (!isUploading) {
        return null;
    }

    if ((progress?.percentage ?? 0) >= 100) {
        return 'processing';
    }

    return 'uploading';
}
