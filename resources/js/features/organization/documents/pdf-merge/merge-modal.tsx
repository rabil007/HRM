import { Loader2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
} from '@/components/ui/dialog';
import { FilenameInput } from '@/features/organization/documents/pdf-merge/filename-input';
import {
    buildDefaultMergeFilename,
    sanitizeMergeFilename,
} from '@/features/organization/documents/pdf-merge/merge-filename';
import { MergeList } from '@/features/organization/documents/pdf-merge/merge-list';
import { MergeToolbar } from '@/features/organization/documents/pdf-merge/merge-toolbar';
import { clearPdfPreviewCache } from '@/features/organization/documents/pdf-merge/pdf-preview-service';
import type { MergeDocumentItem } from '@/features/organization/documents/pdf-merge/types';
import { downloadBinaryExport } from '@/features/organization/documents/shared/download-binary-export';
import { toast } from '@/lib/toast';
import { mergePdf } from '@/routes/organization/documents/employee/files';

type PdfMergeModalProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee: { id: number; name: string };
    documents: MergeDocumentItem[];
    onMergeComplete: () => void;
};

export function PdfMergeModal({
    open,
    onOpenChange,
    employee,
    documents: initialDocuments,
    onMergeComplete,
}: PdfMergeModalProps) {
    const [orderedDocuments, setOrderedDocuments] = useState<MergeDocumentItem[]>(initialDocuments);
    const [filename, setFilename] = useState(() => buildDefaultMergeFilename(employee.name));
    const [pageCounts, setPageCounts] = useState<Record<number, number | null>>({});
    const [isDownloading, setIsDownloading] = useState(false);

    useEffect(() => {
        if (open) {
            setOrderedDocuments(initialDocuments);
            setFilename(buildDefaultMergeFilename(employee.name));
            setPageCounts({});
        } else {
            clearPdfPreviewCache();
        }
    }, [employee.name, initialDocuments, open]);

    const estimatedSizeBytes = useMemo(
        () =>
            orderedDocuments.reduce(
                (total, document) => total + (document.size_bytes ?? 0),
                0,
            ),
        [orderedDocuments],
    );

    const handleReorder = useCallback((orderedIds: number[]) => {
        setOrderedDocuments((current) => {
            const byId = new Map(current.map((document) => [document.id, document]));

            return orderedIds
                .map((id) => byId.get(id))
                .filter((document): document is MergeDocumentItem => document !== undefined);
        });
    }, []);

    const handlePreviewLoaded = useCallback((documentId: number, pageCount: number) => {
        setPageCounts((current) => ({
            ...current,
            [documentId]: pageCount,
        }));
    }, []);

    const handleDownload = async () => {
        const sanitizedFilename = sanitizeMergeFilename(filename);

        if (sanitizedFilename === '') {
            toast.error('Enter a valid output filename.');

            return;
        }

        setIsDownloading(true);

        try {
            await downloadBinaryExport(
                mergePdf.url({ employee: employee.id }),
                {
                    document_ids: orderedDocuments.map((document) => document.id),
                    download_name: sanitizedFilename,
                },
                'application/pdf',
                `${sanitizedFilename}.pdf`,
                'Merge failed. Please try again.',
            );
            onOpenChange(false);
            onMergeComplete();
            toast.success('Merged PDF downloaded.');
        } catch (error) {
            toast.error(
                error instanceof Error ? error.message : 'Merge failed. Please try again.',
            );
        } finally {
            setIsDownloading(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[85vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
                <MergeToolbar
                    documentCount={orderedDocuments.length}
                    estimatedSizeBytes={estimatedSizeBytes}
                />

                <div className="max-h-[min(420px,50vh)] overflow-y-auto px-5 py-4">
                    <MergeList
                        documents={orderedDocuments}
                        pageCounts={pageCounts}
                        onReorder={handleReorder}
                        onPreviewLoaded={handlePreviewLoaded}
                    />
                </div>

                <div className="border-t border-border px-5 py-4 dark:border-white/10">
                    <FilenameInput
                        value={filename}
                        onChange={setFilename}
                        disabled={isDownloading}
                    />
                </div>

                <DialogFooter className="border-t border-border px-5 py-4 sm:justify-end dark:border-white/10">
                    <Button
                        type="button"
                        variant="outline"
                        className="rounded-lg"
                        disabled={isDownloading}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className="rounded-lg"
                        disabled={isDownloading || orderedDocuments.length < 2}
                        onClick={handleDownload}
                    >
                        {isDownloading ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Merging…
                            </>
                        ) : (
                            'Download Merged PDF'
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
