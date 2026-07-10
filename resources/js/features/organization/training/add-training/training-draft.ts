import {
    createUploadDraftId,
    fileMatchesExistingDraft,
    firstInvalidDraftIndex,
    formatUploadFileSize,
    SUPPORTED_UPLOAD_MIME_TYPES,
} from '@/features/organization/documents/upload/upload-draft';
import {
    omitHiddenTemplateRecordFields,
    createTemplateFieldVisibility,
    isEmptyTemplateFieldValue,
} from '@/pages/organization/_lib/template-field-visibility';
import type { TemplateFieldConfig } from '@/pages/organization/employee-page.types';

export const TRAINING_REQUEST_FIELD_ALIASES = {
    certificate: 'certificate_path',
} as const;

function nullableIdToString(value: number | null | undefined): string {
    return value !== null && value !== undefined ? String(value) : '';
}

function nullableString(value: string | null | undefined): string {
    return value ?? '';
}

export const MAX_TRAINING_CERTIFICATE_FILES = 20;

export {
    SUPPORTED_UPLOAD_MIME_TYPES,
    formatUploadFileSize,
    fileMatchesExistingDraft,
    createUploadDraftId,
    firstInvalidDraftIndex,
};

const BULK_TRAINING_ERROR_PATTERN = /^trainings\.(\d+)\.(.+)$/;

export function parseBulkTrainingErrors(
    errors: Record<string, string | string[]>,
): Map<number, TrainingDraftFieldErrors> {
    const byIndex = new Map<number, TrainingDraftFieldErrors>();

    for (const [key, rawValue] of Object.entries(errors)) {
        const match = key.match(BULK_TRAINING_ERROR_PATTERN);

        if (!match) {
            continue;
        }

        const index = Number(match[1]);
        const fieldKey = match[2];
        const message = Array.isArray(rawValue)
            ? (rawValue[0] ?? '')
            : rawValue;

        if (!message) {
            continue;
        }

        const existing = byIndex.get(index) ?? {};
        const mappedField =
            fieldKey === 'certificate'
                ? 'certificate'
                : fieldKey in emptyTrainingMetadata()
                  ? (fieldKey as keyof TrainingDraftMetadata)
                  : null;

        if (mappedField) {
            existing[mappedField] = message;
            byIndex.set(index, existing);
        }
    }

    return byIndex;
}

function emptyTrainingMetadata(): TrainingDraftMetadata {
    return {
        course_id: '',
        issue_date: '',
        expiry_date: '',
        institute_center: '',
        country_id: '',
    };
}

export function buildBulkTrainingSubmitPayload(
    drafts: TrainingDraft[],
    templateFields?: Record<string, TemplateFieldConfig> | null,
): Record<string, unknown> {
    return {
        trainings: drafts.map((draft) =>
            omitHiddenTemplateRecordFields(
                {
                    course_id: draft.course_id,
                    issue_date: draft.issue_date,
                    expiry_date:
                        draft.expiry_date === '' ? null : draft.expiry_date,
                    institute_center: draft.institute_center.trim(),
                    country_id:
                        draft.country_id === '' ? null : draft.country_id,
                    certificate: draft.file,
                },
                templateFields,
                TRAINING_REQUEST_FIELD_ALIASES,
            ),
        ),
    };
}

export type TrainingDraftMetadata = {
    course_id: string;
    issue_date: string;
    expiry_date: string;
    institute_center: string;
    country_id: string;
};

export type TrainingDraft = TrainingDraftMetadata & {
    id: string;
    file: File;
};

export type TrainingDraftFieldErrors = Partial<
    Record<keyof TrainingDraftMetadata | 'certificate', string>
>;

export function createTrainingDraftFromFile(file: File): TrainingDraft {
    return {
        id: createUploadDraftId(),
        file,
        course_id: '',
        issue_date: '',
        expiry_date: '',
        institute_center: '',
        country_id: '',
    };
}

export function copyTrainingMetadataFromSource(
    source: TrainingDraftMetadata,
): TrainingDraftMetadata {
    return {
        course_id: source.course_id,
        issue_date: source.issue_date,
        expiry_date: source.expiry_date,
        institute_center: source.institute_center,
        country_id: source.country_id,
    };
}

export function trainingDraftToFormData(
    draft: TrainingDraftMetadata,
    certificate: File | null,
    existingCertificatePath: string | null = null,
): Record<string, unknown> {
    return {
        course_id: draft.course_id,
        issue_date: draft.issue_date,
        expiry_date: draft.expiry_date === '' ? null : draft.expiry_date,
        institute_center: draft.institute_center.trim(),
        country_id: draft.country_id === '' ? null : draft.country_id,
        certificate_path: certificate ?? existingCertificatePath,
    };
}

export function buildTrainingSubmitPayload(
    draft: TrainingDraftMetadata,
    options: {
        templateFields?: Record<string, TemplateFieldConfig> | null;
        certificate?: File | null;
    } = {},
): Record<string, unknown> {
    const payload: Record<string, unknown> = {
        course_id: draft.course_id,
        issue_date: draft.issue_date,
        expiry_date: draft.expiry_date === '' ? null : draft.expiry_date,
        institute_center: draft.institute_center.trim(),
        country_id: draft.country_id === '' ? null : draft.country_id,
    };

    if (options.certificate !== undefined) {
        payload.certificate = options.certificate;
    }

    return omitHiddenTemplateRecordFields(
        payload,
        options.templateFields,
        TRAINING_REQUEST_FIELD_ALIASES,
    );
}

export function hasVisibleTrainingContent(
    draft: TrainingDraftMetadata,
    options: {
        templateFields?: Record<string, TemplateFieldConfig> | null;
        certificate?: File | null;
        existingCertificate?: string | null;
    } = {},
): boolean {
    const showField = createTemplateFieldVisibility(options.templateFields);
    const data = trainingDraftToFormData(
        draft,
        options.certificate ?? null,
        options.existingCertificate ?? null,
    );

    for (const fieldKey of [
        'course_id',
        'issue_date',
        'expiry_date',
        'institute_center',
        'country_id',
    ] as const) {
        if (!showField(fieldKey)) {
            continue;
        }

        if (!isEmptyTemplateFieldValue(data[fieldKey])) {
            return true;
        }
    }

    if (!showField('certificate_path')) {
        return false;
    }

    if (options.certificate instanceof File) {
        return true;
    }

    return Boolean(options.existingCertificate);
}

export function trainingMetadataFromItem(item: {
    course_id: number | null;
    issue_date: string | null;
    expiry_date: string | null;
    institute_center: string | null;
    country_id: number | null;
}): TrainingDraftMetadata {
    return {
        course_id: nullableIdToString(item.course_id),
        issue_date: nullableString(item.issue_date),
        expiry_date: nullableString(item.expiry_date),
        institute_center: nullableString(item.institute_center),
        country_id: nullableIdToString(item.country_id),
    };
}
