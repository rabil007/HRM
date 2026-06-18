import {
    DocumentPreviewPanel,
    type DocumentPreviewSource,
} from '@/features/organization/documents/shared/document-preview-panel';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export function DocumentPreviewDialog({
    document,
    onOpenChange,
}: {
    document: DocumentPreviewSource | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={!!document} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        {document?.title || document?.document_type_label || 'Document preview'}
                    </DialogTitle>
                </DialogHeader>
                {document ? <DocumentPreviewPanel document={document} /> : null}
            </DialogContent>
        </Dialog>
    );
}
