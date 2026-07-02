import { Eye, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type SavedPreview = {
    mode: 'saved';
    templateId: number;
    label: string;
    subject: string;
};

type DraftPreview = {
    mode: 'draft';
    slug: string;
    label: string;
    subject: string;
    bodyHtml: string;
    includeCompanyFooter: boolean;
};

export type EmailTemplatePreviewTarget = SavedPreview | DraftPreview;

type EmailTemplatePreviewDialogProps = {
    target: EmailTemplatePreviewTarget | null;
    onOpenChange: (open: boolean) => void;
};

function getCsrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export function EmailTemplatePreviewDialog({
    target,
    onOpenChange,
}: EmailTemplatePreviewDialogProps) {
    const open = target !== null;
    const [draftHtml, setDraftHtml] = useState<string | null>(null);
    const [draftSubject, setDraftSubject] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open || target === null || target.mode !== 'draft') {
            setDraftHtml(null);
            setDraftSubject(null);
            setError(null);
            setLoading(false);

            return;
        }

        let cancelled = false;

        const loadDraftPreview = async () => {
            setLoading(true);
            setError(null);
            setDraftHtml(null);

            try {
                const response = await fetch(
                    '/settings/application/email-templates/preview',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            slug: target.slug,
                            subject: target.subject,
                            body_html: target.bodyHtml,
                            include_company_footer: target.includeCompanyFooter,
                        }),
                    },
                );

                if (!response.ok) {
                    throw new Error('Preview could not be generated.');
                }

                const data = (await response.json()) as {
                    subject?: string;
                    html?: string;
                };

                if (!cancelled) {
                    setDraftSubject(data.subject ?? target.subject);
                    setDraftHtml(data.html ?? null);
                }
            } catch {
                if (!cancelled) {
                    setError(
                        'Preview could not be generated. Check the template fields and try again.',
                    );
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        };

        void loadDraftPreview();

        return () => {
            cancelled = true;
        };
    }, [open, target]);

    const subject =
        target?.mode === 'draft'
            ? (draftSubject ?? target.subject)
            : (target?.subject ?? '');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] w-[min(960px,calc(100vw-2rem))] max-w-[960px] flex-col gap-0 overflow-hidden glass-card p-0 sm:max-w-[960px]">
                <DialogHeader className="space-y-1 border-b border-border/80 px-6 py-4 dark:border-white/10">
                    <div className="flex items-center gap-2">
                        <Eye className="h-4 w-4 text-primary" />
                        <DialogTitle>Email preview</DialogTitle>
                    </div>
                    <DialogDescription className="text-left">
                        {target ? (
                            <>
                                <span className="font-medium text-foreground dark:text-zinc-200">
                                    {target.label}
                                </span>
                                {subject ? (
                                    <>
                                        {' '}
                                        —{' '}
                                        <span className="text-muted-foreground">
                                            {subject}
                                        </span>
                                    </>
                                ) : null}
                            </>
                        ) : (
                            'Sample data is used for placeholders.'
                        )}
                    </DialogDescription>
                </DialogHeader>

                <div className="relative min-h-[420px] flex-1 bg-muted/30 dark:bg-black/20">
                    {target?.mode === 'saved' ? (
                        <iframe
                            title={`Preview of ${target.label}`}
                            src={`/settings/application/email-templates/${target.templateId}/preview`}
                            className="h-[min(70vh,720px)] w-full border-0 bg-white"
                        />
                    ) : null}

                    {target?.mode === 'draft' && loading ? (
                        <div className="flex h-[min(70vh,720px)] items-center justify-center gap-2 text-sm text-muted-foreground">
                            <Loader2 className="h-4 w-4 animate-spin" />
                            Generating preview…
                        </div>
                    ) : null}

                    {target?.mode === 'draft' && error ? (
                        <div className="flex h-[min(70vh,720px)] items-center justify-center px-6 text-center text-sm text-destructive">
                            {error}
                        </div>
                    ) : null}

                    {target?.mode === 'draft' &&
                    !loading &&
                    !error &&
                    draftHtml ? (
                        <iframe
                            title={`Draft preview of ${target.label}`}
                            srcDoc={draftHtml}
                            className="h-[min(70vh,720px)] w-full border-0 bg-white"
                        />
                    ) : null}
                </div>

                <p className="border-t border-border/80 px-6 py-3 text-xs text-muted-foreground dark:border-white/10">
                    Preview uses sample placeholder values. Actual emails use
                    real employee and request data when sent.
                </p>
            </DialogContent>
        </Dialog>
    );
}
