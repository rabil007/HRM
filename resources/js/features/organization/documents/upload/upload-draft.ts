export const MAX_UPLOAD_FILES = 20;

export const SUPPORTED_UPLOAD_MIME_TYPES = [
    'application/pdf',
    'image/jpeg',
    'image/png',
] as const;

export type UploadDraftMetadata = {
    document_type_id: string;
    title: string;
    document_number: string;
    issue_date: string;
    expiry_date: string;
    notes: string;
};

export type UploadDraft = UploadDraftMetadata & {
    id: string;
    file: File;
};

export type UploadDraftFieldErrors = Partial<Record<keyof UploadDraftMetadata | 'file', string>>;

let uploadDraftIdCounter = 0;

export function createUploadDraftId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    uploadDraftIdCounter += 1;

    return `upload-${Date.now()}-${uploadDraftIdCounter}-${Math.random().toString(36).slice(2, 11)}`;
}

export function defaultTitleFromFile(file: File): string {
    const name = file.name;
    const lastDot = name.lastIndexOf('.');

    if (lastDot > 0) {
        return name.slice(0, lastDot);
    }

    return name;
}

export function createUploadDraftFromFile(file: File): UploadDraft {
    return {
        id: createUploadDraftId(),
        file,
        document_type_id: '',
        title: defaultTitleFromFile(file),
        document_number: '',
        issue_date: '',
        expiry_date: '',
        notes: '',
    };
}

export function fileMatchesExistingDraft(drafts: UploadDraft[], file: File): boolean {
    return drafts.some(
        (draft) =>
            draft.file.name === file.name &&
            draft.file.size === file.size &&
            draft.file.lastModified === file.lastModified,
    );
}

export function allDraftsHaveDocumentType(drafts: UploadDraft[]): boolean {
    return drafts.length > 0 && drafts.every((draft) => draft.document_type_id !== '');
}

export function copyMetadataFromSource(source: UploadDraftMetadata): UploadDraftMetadata {
    return {
        document_type_id: source.document_type_id,
        title: source.title,
        document_number: source.document_number,
        issue_date: source.issue_date,
        expiry_date: source.expiry_date,
        notes: source.notes,
    };
}

export function formatUploadFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const BULK_DOCUMENT_ERROR_PATTERN = /^documents\.(\d+)\.(.+)$/;

export function parseBulkDocumentErrors(
    errors: Record<string, string | string[]>,
): Map<number, UploadDraftFieldErrors> {
    const byIndex = new Map<number, UploadDraftFieldErrors>();

    for (const [key, rawValue] of Object.entries(errors)) {
        const match = key.match(BULK_DOCUMENT_ERROR_PATTERN);

        if (!match) {
            continue;
        }

        const index = Number(match[1]);
        const field = match[2] as keyof UploadDraftFieldErrors;
        const message = Array.isArray(rawValue) ? (rawValue[0] ?? '') : rawValue;

        if (!message) {
            continue;
        }

        const existing = byIndex.get(index) ?? {};
        existing[field] = message;
        byIndex.set(index, existing);
    }

    return byIndex;
}

export function firstInvalidDraftIndex(
    errorsByIndex: Map<number, UploadDraftFieldErrors>,
): number | null {
    const indices = [...errorsByIndex.keys()].sort((a, b) => a - b);

    return indices.length > 0 ? indices[0] : null;
}

export function validationErrorMessage(
    value: string | string[] | undefined,
): string | undefined {
    if (Array.isArray(value)) {
        return value[0];
    }

    return typeof value === 'string' && value.trim() !== '' ? value : undefined;
}
