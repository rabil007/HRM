export function generationActionLabel({
    isBusy,
    selectedCount,
    missingCount,
}: {
    isBusy: boolean;
    selectedCount: number;
    missingCount: number;
}): string {
    if (isBusy) {
        return 'Generating…';
    }

    if (selectedCount > 0) {
        return `Generate for ${selectedCount} selected`;
    }

    if (missingCount === 0) {
        return 'All documents generated';
    }

    return `Generate ${missingCount} missing`;
}
