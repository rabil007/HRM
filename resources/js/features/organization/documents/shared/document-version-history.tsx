import { Download, ExternalLink } from 'lucide-react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDisplayDate } from '@/lib/format-date';
import { formatBytes } from '@/lib/utils';

export type DocumentVersionItem = {
    id: number;
    version: number;
    file_url: string;
    original_filename: string | null;
    mime_type: string | null;
    size_bytes: number | null;
    replaced_by: string | null;
    created_at: string | null;
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

export function DocumentVersionHistory({
    versions,
    showDownload = false,
    emptyMessage = 'No version history found.',
}: {
    versions: readonly DocumentVersionItem[];
    showDownload?: boolean;
    emptyMessage?: string;
}): ReactElement {
    if (versions.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                {emptyMessage}
            </p>
        );
    }

    return (
        <ol className="relative ml-3 space-y-0 border-l border-border/50">
            {versions.map((version, index) => (
                <li key={version.id} className="relative mb-6 pl-6">
                    <span className="absolute top-1.5 -left-[9px] flex h-4 w-4 items-center justify-center rounded-full border-2 border-background bg-border">
                        <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground" />
                    </span>

                    <div className="rounded-xl border border-border/50 bg-card/40 p-4">
                        <div className="mb-2 flex items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <Badge
                                    variant={
                                        index === 0 ? 'default' : 'secondary'
                                    }
                                    className="text-[10px] uppercase"
                                >
                                    v{version.version}
                                </Badge>
                                {index === 0 ? (
                                    <span className="text-[10px] font-medium text-emerald-400">
                                        Current
                                    </span>
                                ) : null}
                            </div>
                            <span className="text-[10px] text-muted-foreground/60 tabular-nums">
                                {formatBytes(version.size_bytes)}
                            </span>
                        </div>

                        <p className="mb-1 truncate text-xs font-medium text-foreground/80">
                            {version.original_filename ?? 'Unknown file'}
                        </p>

                        <div className="flex items-center justify-between text-[10px] text-muted-foreground/60">
                            <span>{mimeLabel(version.mime_type)}</span>
                            <span>{formatDisplayDate(version.created_at)}</span>
                        </div>

                        {version.replaced_by ? (
                            <p className="mt-1 text-[10px] text-muted-foreground/50">
                                Uploaded by {version.replaced_by}
                            </p>
                        ) : null}

                        <div className="mt-3 flex items-center gap-2">
                            <Button
                                asChild
                                variant="ghost"
                                size="sm"
                                className="h-7 gap-1.5 rounded-lg px-2 text-[11px]"
                            >
                                <a
                                    href={version.file_url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink className="h-3 w-3" />
                                    View
                                </a>
                            </Button>
                            {showDownload ? (
                                <Button
                                    asChild
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 gap-1.5 rounded-lg px-2 text-[11px] text-muted-foreground"
                                >
                                    <a
                                        href={version.file_url}
                                        download={
                                            version.original_filename ??
                                            undefined
                                        }
                                    >
                                        <Download className="h-3 w-3" />
                                        Download
                                    </a>
                                </Button>
                            ) : null}
                        </div>
                    </div>
                </li>
            ))}
        </ol>
    );
}
