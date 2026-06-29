import type { WpsPreview } from '../types';

export type WpsSkippedRecord = WpsPreview['skipped'][number];

export type WpsSelectionSummary = {
    selectedCount: number;
    eligibleCount: number;
    skippedInSelection: number;
    companyConfigMissing: boolean;
    skippedRecords: WpsSkippedRecord[];
    companyIssues: WpsSkippedRecord[];
};

export function summarizeWpsSelection(
    preview: WpsPreview,
    selectedRecordIds: number[],
): WpsSelectionSummary {
    const companyConfigMissing =
        !preview.company.wps_mol_uid ||
        !preview.company.wps_agent_code ||
        !preview.company.wps_employer_iban;

    const companyIssues = preview.skipped.filter((row) => row.record_id === 0);
    const skippedByRecordId = new Map(
        preview.skipped
            .filter((row) => row.record_id > 0)
            .map((row) => [row.record_id, row]),
    );

    let eligibleCount = 0;
    let skippedInSelection = 0;
    const skippedRecords: WpsSkippedRecord[] = [];

    if (!companyConfigMissing) {
        for (const id of selectedRecordIds) {
            const skipped = skippedByRecordId.get(id);

            if (skipped !== undefined) {
                skippedInSelection++;
                skippedRecords.push(skipped);
            } else {
                eligibleCount++;
            }
        }
    }

    return {
        selectedCount: selectedRecordIds.length,
        eligibleCount,
        skippedInSelection,
        companyConfigMissing,
        skippedRecords,
        companyIssues,
    };
}

export function summarizeWpsPeriod(preview: WpsPreview): WpsSelectionSummary {
    const skippedRecords = preview.skipped.filter((row) => row.record_id > 0);

    return {
        selectedCount: preview.eligible_count + skippedRecords.length,
        eligibleCount: preview.eligible_count,
        skippedInSelection: skippedRecords.length,
        companyConfigMissing:
            !preview.company.wps_mol_uid ||
            !preview.company.wps_agent_code ||
            !preview.company.wps_employer_iban,
        skippedRecords,
        companyIssues: preview.skipped.filter((row) => row.record_id === 0),
    };
}
