import { useHttp } from '@inertiajs/react';
import { Download, ExternalLink, History, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { formatDisplayDate } from '@/lib/format-date';
import { formatBytes } from '@/lib/utils';

type DocumentVersion = {
    id: number;
    version: number;
    file_url: string;
    original_filename: string | null;
    mime_type: string | null;
    size_bytes: number | null;
    replaced_by: string | null;
    created_at: string | null;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number | null;
    documentId: number | null;
    documentTitle: string | null;
};

function mimeLabel(mime: string | null): string {
    if (!mime) {
return 'File';
}

    if (mime === 'application/pdf') {
return 'PDF';
}

    if (mime.startsWith('image/')) {
return mime.replace('image/', '').toUpperCase();
}

    return mime.split('/').pop()?.toUpperCase() ?? 'File';
}

export function DocumentVersionsSheet({ open, onOpenChange, employeeId, documentId, documentTitle }: Props) {
    const [versions, setVersions] = useState<DocumentVersion[]>([]);
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
                    setVersions((res.data as { versions: DocumentVersion[] }).versions ?? []);
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
        // http is stable and does not need to be in deps
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, employeeId, documentId]);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="w-full sm:max-w-lg overflow-y-auto">
                <SheetHeader className="mb-6">
                    <div className="flex items-center gap-2">
                        <History className="h-4 w-4 text-muted-foreground" />
                        <SheetTitle className="text-base">Version history</SheetTitle>
                    </div>
                    {documentTitle ? (
                        <p className="text-sm text-muted-foreground truncate">{documentTitle}</p>
                    ) : null}
                </SheetHeader>

                {loading ? (
                    <div className="flex items-center justify-center py-16 text-muted-foreground">
                        <Loader2 className="h-5 w-5 animate-spin" />
                    </div>
                ) : versions.length === 0 ? (
                    <p className="py-12 text-center text-sm text-muted-foreground">No version history found.</p>
                ) : (
                    <ol className="relative border-l border-border/50 space-y-0 ml-3">
                        {versions.map((v, idx) => (
                            <li key={v.id} className="relative mb-6 pl-6">
                                <span className="absolute -left-[9px] top-1.5 h-4 w-4 rounded-full border-2 border-background bg-border flex items-center justify-center">
                                    <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground" />
                                </span>

                                <div className="rounded-xl border border-border/50 bg-card/40 p-4">
                                    <div className="flex items-center justify-between gap-2 mb-2">
                                        <div className="flex items-center gap-2">
                                            <Badge variant={idx === 0 ? 'default' : 'secondary'} className="text-[10px] uppercase">
                                                v{v.version}
                                            </Badge>
                                            {idx === 0 ? (
                                                <span className="text-[10px] font-medium text-emerald-400">Current</span>
                                            ) : null}
                                        </div>
                                        <span className="text-[10px] text-muted-foreground/60 tabular-nums">
                                            {formatBytes(v.size_bytes)}
                                        </span>
                                    </div>

                                    <p className="truncate text-xs font-medium text-foreground/80 mb-1">
                                        {v.original_filename ?? 'Unknown file'}
                                    </p>

                                    <div className="flex items-center justify-between text-[10px] text-muted-foreground/60">
                                        <span>{mimeLabel(v.mime_type)}</span>
                                        <span>{formatDisplayDate(v.created_at)}</span>
                                    </div>

                                    {v.replaced_by ? (
                                        <p className="mt-1 text-[10px] text-muted-foreground/50">
                                            Uploaded by {v.replaced_by}
                                        </p>
                                    ) : null}

                                    <div className="mt-3 flex items-center gap-2">
                                        <Button asChild variant="ghost" size="sm" className="h-7 gap-1.5 rounded-lg px-2 text-[11px]">
                                            <a href={v.file_url} target="_blank" rel="noreferrer">
                                                <ExternalLink className="h-3 w-3" />
                                                View
                                            </a>
                                        </Button>
                                        <Button asChild variant="ghost" size="sm" className="h-7 gap-1.5 rounded-lg px-2 text-[11px] text-muted-foreground">
                                            <a href={v.file_url} download={v.original_filename ?? undefined}>
                                                <Download className="h-3 w-3" />
                                                Download
                                            </a>
                                        </Button>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </SheetContent>
        </Sheet>
    );
}
