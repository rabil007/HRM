import { Download, FolderSearch, Share2, Upload, X } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { documentsLibraryActionMessage } from './documents-library-action';
import type { DocumentsLibraryAction } from './documents-library-action';

export function DocumentsLibraryHeader({
    documentCount,
    canUpload,
    canShare,
    canDownload,
    action,
    onActionChange,
    onFindEmployee,
}: {
    documentCount: number;
    canUpload: boolean;
    canShare: boolean;
    canDownload: boolean;
    action: DocumentsLibraryAction;
    onActionChange: (action: DocumentsLibraryAction) => void;
    onFindEmployee: () => void;
}) {
    const actionMessage = documentsLibraryActionMessage(action);

    return (
        <>
            <PageHeader
                kicker="Documents"
                title="Library"
                description={`${documentCount.toLocaleString()} documents in this company. Browse by employee, then find, update, download, or share uploaded files.`}
                right={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11 rounded-xl"
                            onClick={onFindEmployee}
                        >
                            <FolderSearch className="h-4 w-4" />
                            Find employee
                        </Button>
                        {canUpload ? (
                            <Button
                                type="button"
                                className="h-11 rounded-xl px-5 shadow-lg shadow-primary/20"
                                onClick={() => onActionChange('upload')}
                            >
                                <Upload className="h-4 w-4" />
                                Upload document
                            </Button>
                        ) : null}
                    </>
                }
            />

            <section
                className="mb-6 rounded-xl border border-border/70 bg-muted/20 p-3 dark:border-white/5 dark:bg-white/[0.02]"
                aria-label="Library quick actions"
            >
                <div className="flex flex-wrap items-center gap-2">
                    <span className="mr-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Quick actions
                    </span>
                    {canUpload ? (
                        <Button
                            type="button"
                            size="sm"
                            variant={
                                action === 'upload' ? 'secondary' : 'ghost'
                            }
                            className="rounded-lg"
                            onClick={() => onActionChange('upload')}
                        >
                            <Upload className="h-4 w-4" />
                            Upload
                        </Button>
                    ) : null}
                    {canShare ? (
                        <Button
                            type="button"
                            size="sm"
                            variant={action === 'share' ? 'secondary' : 'ghost'}
                            className="rounded-lg"
                            onClick={() => onActionChange('share')}
                        >
                            <Share2 className="h-4 w-4" />
                            Share securely
                        </Button>
                    ) : null}
                    {canDownload ? (
                        <Button
                            type="button"
                            size="sm"
                            variant={
                                action === 'download' ? 'secondary' : 'ghost'
                            }
                            className="rounded-lg"
                            onClick={() => onActionChange('download')}
                        >
                            <Download className="h-4 w-4" />
                            Download folders
                        </Button>
                    ) : null}
                </div>

                {actionMessage ? (
                    <div
                        className="mt-3 flex items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2.5 text-sm text-foreground"
                        role="status"
                    >
                        <p>{actionMessage}</p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7 shrink-0 rounded-md"
                            onClick={() => onActionChange(null)}
                            aria-label="Dismiss action guidance"
                        >
                            <X className="h-4 w-4" />
                        </Button>
                    </div>
                ) : null}
            </section>
        </>
    );
}
