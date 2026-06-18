import { History, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import {
    DocumentVersionHistory,
    type DocumentVersionItem,
} from '@/features/organization/documents/shared/document-version-history';
import { useHttp } from '@inertiajs/react';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number | null;
    documentId: number | null;
    documentTitle: string | null;
    showDownload?: boolean;
};

export function DocumentVersionsSheet({
    open,
    onOpenChange,
    employeeId,
    documentId,
    documentTitle,
    showDownload = false,
}: Props) {
    const [versions, setVersions] = useState<DocumentVersionItem[]>([]);
    const [loading, setLoading] = useState(false);
    const http = useHttp();

    useEffect(() => {
        if (!open || !employeeId || !documentId) {
            return;
        }

        let cancelled = false;
        setLoading(true);

        http.get(EmployeeDocumentController.versions.url({ employee: employeeId, document: documentId }))
            .then((res) => {
                if (!cancelled) {
                    setVersions((res.data as { versions: DocumentVersionItem[] }).versions ?? []);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, employeeId, documentId]);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader className="mb-6">
                    <div className="flex items-center gap-2">
                        <History className="h-4 w-4 text-muted-foreground" />
                        <SheetTitle className="text-base">Version history</SheetTitle>
                    </div>
                    {documentTitle ? (
                        <p className="truncate text-sm text-muted-foreground">{documentTitle}</p>
                    ) : null}
                </SheetHeader>

                {loading ? (
                    <div className="flex items-center justify-center py-16 text-muted-foreground">
                        <Loader2 className="h-5 w-5 animate-spin" />
                    </div>
                ) : (
                    <DocumentVersionHistory versions={versions} showDownload={showDownload} />
                )}
            </SheetContent>
        </Sheet>
    );
}
