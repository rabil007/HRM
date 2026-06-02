import {
    createUploadDraftId,
    fileMatchesExistingDraft,
    formatUploadFileSize,
    SUPPORTED_UPLOAD_MIME_TYPES,
} from '@/features/organization/documents/upload/upload-draft';

export const MAX_TRAINING_CERTIFICATE_FILES = 20;

export {
    SUPPORTED_UPLOAD_MIME_TYPES,
    formatUploadFileSize,
    fileMatchesExistingDraft,
    createUploadDraftId,
};

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

export function trainingMetadataFromItem(item: {
    course_id: number;
    issue_date: string;
    expiry_date: string | null;
    institute_center: string;
    country_id: number | null;
}): TrainingDraftMetadata {
    return {
        course_id: String(item.course_id),
        issue_date: item.issue_date,
        expiry_date: item.expiry_date ?? '',
        institute_center: item.institute_center,
        country_id: item.country_id ? String(item.country_id) : '',
    };
}
