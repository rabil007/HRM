import type { CandidateFormData, CandidateIndexRow } from '../types';

export function emptyCandidateForm(
    prefills?: Partial<CandidateFormData>,
): CandidateFormData {
    return {
        recruitment_requirement_id: '',
        recruitment_requirement_line_id: '',
        name: '',
        email: '',
        phone: '',
        nationality_id: '',
        source: '',
        notes: '',
        cv: null,
        remove_cv: false,
        ignore_duplicate_warning: false,
        lock_version: null,
        ...prefills,
    };
}

export function candidateFormFromRow(
    candidate: CandidateIndexRow & {
        nationality_id?: number | null;
        notes?: string | null;
        source?: string | null;
    },
): CandidateFormData {
    return {
        recruitment_requirement_id: candidate.requirement_id
            ? String(candidate.requirement_id)
            : '',
        recruitment_requirement_line_id: candidate.requirement_line_id
            ? String(candidate.requirement_line_id)
            : '',
        name: candidate.name,
        email: candidate.email ?? '',
        phone: candidate.phone ?? '',
        nationality_id: candidate.nationality_id
            ? String(candidate.nationality_id)
            : '',
        source: candidate.source ?? '',
        notes: candidate.notes ?? '',
        cv: null,
        remove_cv: false,
        ignore_duplicate_warning: false,
        lock_version: candidate.lock_version,
    };
}
