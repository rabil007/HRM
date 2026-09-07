import type { DocumentVersionItem } from '@/features/organization/documents/shared/document-version-history';
import type { TrainingItem } from '@/pages/organization/employee-page.types';

export type TrainingShowItem = TrainingItem & {
    certificate_original_filename: string | null;
    certificate_mime_type: string | null;
    certificate_size_bytes: number | null;
    current_version: number;
    replaced_at: string | null;
    can_preview: boolean;
    versions: DocumentVersionItem[];
    source_crew_assignment_phase_id?: number | null;
    source_crew_assignment?: {
        id: number;
        assignment_no: string;
    } | null;
    is_from_crew_operations?: boolean;
};

export type TrainingEmployeeSummary = {
    id: number;
    name: string;
    employee_no: string;
};
