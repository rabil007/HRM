import { Link } from '@inertiajs/react';
import { Eye, ExternalLink, FileImage, FileText, FileType, History } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatDisplayDate } from '@/lib/format-date';
import { formatBytes } from '@/lib/utils';
import { DOCUMENT_STATUS_VARIANTS, documentStatusLabel } from './status';

type DocumentCardProps = {
    id: number;
    employee_id: number;
    employee_no: string;
    employee_name: string;
    document_type_label: string | null;
    title: string | null;
    file_url: string;
    mime_type: string | null;
    size_bytes: number | null;
    can_preview: boolean;
    issue_date: string | null;
    expiry_date: string | null;
    document_number: string | null;
    current_version: number | null;
    status: string | null;
};

function FileIcon({ mimeType }: { mimeType: string | null }) {
    if (mimeType?.startsWith('image/')) {
        return <FileImage className="h-8 w-8 opacity-60" />;
    }

    if (mimeType === 'application/pdf') {
        return <FileType className="h-8 w-8 opacity-60" />;
    }

    return <FileText className="h-8 w-8 opacity-60" />;
}

function expiryColor(status: string | null) {
    if (status === 'expired') {
        return 'text-red-400';
    }

    if (status === 'expiring_soon') {
        return 'text-amber-400';
    }

    return 'text-muted-foreground/70';
}

export function DocumentCard({
    doc,
    onPreview,
    onViewHistory,
}: {
    doc: DocumentCardProps;
    onPreview: (doc: DocumentCardProps) => void;
    onViewHistory?: (doc: DocumentCardProps) => void;
}) {
    return (
        <Card className="glass-card group flex flex-col overflow-hidden transition-all duration-200 dark:bg-linear-to-br dark:from-white/6 dark:to-white/3 dark:hover:from-white/8 dark:hover:to-white/4">
            <div className="flex items-center gap-3 border-b border-border/40 px-4 py-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted/50 text-muted-foreground dark:bg-white/6">
                    <FileIcon mimeType={doc.mime_type} />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-foreground">
                        {doc.title ?? doc.document_type_label ?? 'Untitled'}
                    </p>
                    <p className="truncate text-xs text-muted-foreground/70">
                        {doc.document_type_label ?? '—'}
                    </p>
                </div>
                <Badge
                    variant={DOCUMENT_STATUS_VARIANTS[doc.status ?? ''] ?? 'outline'}
                    className="shrink-0 text-[10px] uppercase"
                >
                    {documentStatusLabel(doc.status)}
                </Badge>
            </div>

            <CardContent className="flex flex-1 flex-col gap-3 p-4">
                <Link
                    href={`/organization/employees/${doc.employee_id}#documents`}
                    className="flex items-center gap-2 rounded-lg border border-border/50 bg-muted/30 px-3 py-2 transition-colors hover:border-primary/40 hover:bg-muted/50 dark:bg-white/4"
                >
                    <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary uppercase">
                        {(doc.employee_name ?? '?').charAt(0)}
                    </div>
                    <div className="min-w-0">
                        <p className="truncate text-xs font-semibold text-foreground">{doc.employee_name}</p>
                        <p className="text-[10px] text-muted-foreground/60">{doc.employee_no}</p>
                    </div>
                </Link>

                <div className="grid grid-cols-2 gap-2 text-xs">
                    <div className="rounded-lg border border-border/40 bg-muted/20 px-3 py-2 dark:bg-white/3">
                        <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground/60">Issue date</p>
                        <p className="mt-0.5 font-medium text-foreground/80">{formatDisplayDate(doc.issue_date)}</p>
                    </div>
                    <div className="rounded-lg border border-border/40 bg-muted/20 px-3 py-2 dark:bg-white/3">
                        <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground/60">Expiry</p>
                        <p className={`mt-0.5 font-semibold ${expiryColor(doc.status)}`}>
                            {formatDisplayDate(doc.expiry_date)}
                        </p>
                    </div>
                </div>

                <div className="flex items-center justify-between text-[11px] text-muted-foreground/60">
                    {doc.document_number ? (
                        <span className="truncate font-mono">#{doc.document_number}</span>
                    ) : <span />}
                    <span className="shrink-0 tabular-nums">{formatBytes(doc.size_bytes)}</span>
                </div>

                <div className="mt-auto flex items-center gap-2 border-t border-border/40 pt-3">
                    {doc.can_preview ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 flex-1 gap-1.5 rounded-lg text-xs"
                            onClick={() => onPreview(doc)}
                        >
                            <Eye className="h-3.5 w-3.5" />
                            Preview
                        </Button>
                    ) : null}
                    <Button
                        asChild
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 flex-1 gap-1.5 rounded-lg text-xs text-muted-foreground"
                    >
                        <a href={doc.file_url} target="_blank" rel="noreferrer">
                            <ExternalLink className="h-3.5 w-3.5" />
                            View file
                        </a>
                    </Button>
                    {onViewHistory && (doc.current_version ?? 1) > 1 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 gap-1.5 rounded-lg px-2 text-xs text-muted-foreground"
                            onClick={() => onViewHistory(doc)}
                            title="Version history"
                        >
                            <History className="h-3.5 w-3.5" />
                            v{doc.current_version}
                        </Button>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}
