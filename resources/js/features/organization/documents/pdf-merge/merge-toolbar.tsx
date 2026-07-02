import { formatBytes } from '@/lib/utils';

type MergeToolbarProps = {
    documentCount: number;
    estimatedSizeBytes: number;
};

export function MergeToolbar({
    documentCount,
    estimatedSizeBytes,
}: MergeToolbarProps) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-5 py-4 dark:border-white/10">
            <div>
                <h2 className="text-base font-semibold text-foreground">
                    Merge PDFs
                </h2>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {documentCount} file{documentCount === 1 ? '' : 's'} — drag
                    to reorder
                </p>
            </div>
            <div className="text-right">
                <p className="text-xs tracking-wide text-muted-foreground uppercase">
                    Estimated output
                </p>
                <p className="text-sm font-medium text-foreground dark:text-zinc-200">
                    {formatBytes(estimatedSizeBytes)}
                </p>
            </div>
        </div>
    );
}
