import { formatBytes } from '@/lib/utils';

type MergeToolbarProps = {
    documentCount: number;
    estimatedSizeBytes: number;
};

export function MergeToolbar({ documentCount, estimatedSizeBytes }: MergeToolbarProps) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-white/10 px-5 py-4">
            <div>
                <h2 className="text-base font-semibold text-zinc-100">Merge PDFs</h2>
                <p className="mt-0.5 text-xs text-zinc-400">
                    {documentCount} file{documentCount === 1 ? '' : 's'} — drag to reorder
                </p>
            </div>
            <div className="text-right">
                <p className="text-xs uppercase tracking-wide text-zinc-500">Estimated output</p>
                <p className="text-sm font-medium text-zinc-200">
                    {formatBytes(estimatedSizeBytes)}
                </p>
            </div>
        </div>
    );
}
