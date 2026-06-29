import type { WpsPreview } from '../types';

export type WpsSelectionSummary = {
    selectedCount: number;
    eligibleCount: number;
    skippedInSelection: number;
    companyConfigMissing: boolean;
};

export function summarizeWpsSelection(
    preview: WpsPreview,
    selectedRecordIds: number[],
): WpsSelectionSummary {
    const companyConfigMissing =
        !preview.company.wps_mol_uid || !preview.company.wps_agent_code;

    const skippedRecordIds = new Set(
        preview.skipped.filter((row) => row.record_id > 0).map((row) => row.record_id),
    );

    let eligibleCount = 0;
    let skippedInSelection = 0;

    if (!companyConfigMissing) {
        for (const id of selectedRecordIds) {
            if (skippedRecordIds.has(id)) {
                skippedInSelection++;
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
    };
}
