import { FileText } from 'lucide-react';

import { cn } from '@/lib/utils';

type WhatsAppDocumentTemplatePreviewProps = {
    templateName: string;
    templateLanguage: string;
    bodyText: string;
    sampleFileName?: string;
    className?: string;
};

export function WhatsAppDocumentTemplatePreview({
    templateName,
    templateLanguage,
    bodyText,
    sampleFileName = 'Employee Document.pdf',
    className,
}: WhatsAppDocumentTemplatePreviewProps) {
    return (
        <div className={cn('space-y-3', className)}>
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-bold uppercase tracking-widest text-muted-foreground">
                    Template preview
                </p>
                <p className="text-[10px] font-mono text-muted-foreground">
                    {templateName} · {templateLanguage}
                </p>
            </div>

            <div className="mx-auto w-full max-w-sm overflow-hidden rounded-2xl border border-white/10 bg-[#0b141a] shadow-xl">
                <div className="border-b border-white/5 bg-[#202c33] px-4 py-3">
                    <p className="text-sm font-semibold text-[#e9edef]">Overseas Marine</p>
                    <p className="text-[11px] text-[#8696a0]">Business account</p>
                </div>

                <div className="space-y-3 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxnIGZpbGw9IiMxMTIxMjkiIGZpbGwtb3BhY2l0eT0iMC40Ij48cGF0aCBkPSJNMCAwaDQwdjQwSDB6Ii8+PC9nPjwvZz48L3N2Zz4=')] p-4">
                    <div className="ml-auto max-w-[92%] overflow-hidden rounded-lg rounded-tr-none bg-[#005c4b] shadow-md">
                        <div className="border-b border-white/10 bg-[#025c4b] px-3 py-2">
                            <div className="flex items-center gap-2 text-[#e9edef]">
                                <div className="flex h-9 w-9 items-center justify-center rounded-md bg-[#053d34]">
                                    <FileText className="h-4 w-4" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-xs font-medium">{sampleFileName}</p>
                                    <p className="text-[10px] text-[#99bfb5]">PDF · Document</p>
                                </div>
                            </div>
                        </div>
                        <div className="px-3 py-2.5 text-sm leading-relaxed text-[#e9edef] whitespace-pre-wrap">
                            {bodyText}
                        </div>
                        <div className="flex justify-end px-2 pb-1.5">
                            <span className="text-[10px] text-[#99bfb5]">12:00 PM</span>
                        </div>
                    </div>
                </div>
            </div>

            <p className="text-xs text-muted-foreground">
                Preview shows the employee name substituted for{' '}
                <span className="font-mono text-foreground/80">{'{{name}}'}</span> in your body
                text. The document header uses the actual file name when sending from employee
                documents.
            </p>
        </div>
    );
}

export function renderWhatsAppTemplatePreviewBody(
    bodyTemplate: string,
    sampleName: string,
): string {
    const name = sampleName.trim() !== '' ? sampleName.trim() : 'Employee Name';

    return bodyTemplate.replaceAll('{{name}}', name);
}
