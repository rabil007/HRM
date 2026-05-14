import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type PreviewDocument = {
    title: string | null;
    document_type_label?: string | null;
    file_url: string;
    mime_type?: string | null;
    can_preview?: boolean;
};

export function DocumentPreviewDialog({
    document,
    onOpenChange,
}: {
    document: PreviewDocument | null;
    onOpenChange: (open: boolean) => void;
}) {
    const isImage = document?.mime_type?.startsWith('image/');
    const isPdf = document?.mime_type === 'application/pdf';

    return (
        <Dialog open={!!document} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>{document?.title || document?.document_type_label || 'Document preview'}</DialogTitle>
                </DialogHeader>
                {document ? (
                    <div className="h-[70vh] overflow-hidden rounded-xl border border-border bg-muted/20">
                        {isImage ? (
                            <img src={document.file_url} alt={document.title ?? 'Document'} className="h-full w-full object-contain" />
                        ) : isPdf ? (
                            <iframe src={document.file_url} title={document.title ?? 'Document preview'} className="h-full w-full" />
                        ) : (
                            <div className="flex h-full flex-col items-center justify-center gap-3 text-sm text-muted-foreground">
                                <p>Preview is not available for this file type.</p>
                                <a href={document.file_url} target="_blank" rel="noreferrer" className="font-medium text-primary hover:underline">
                                    Open file
                                </a>
                            </div>
                        )}
                    </div>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
