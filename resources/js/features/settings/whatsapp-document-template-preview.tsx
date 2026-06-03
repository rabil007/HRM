import { FileText } from 'lucide-react';

import { cn } from '@/lib/utils';

export type WhatsAppTemplateHeaderType = 'document' | 'text' | 'none';

export type WhatsAppTemplatePreviewVariables = Record<string, string>;

const DEFAULT_PREVIEW_VARIABLES: WhatsAppTemplatePreviewVariables = {
    '1': 'Passport',
    '2': 'John Smith',
    '3': '04 May 2027',
    name: 'John Smith',
    employee_name: 'John Smith',
    document_type: 'Passport',
    expiry_date: '04 May 2027',
};

const VARIABLE_LABELS: Record<string, string> = {
    '1': 'Variable {{1}} (Meta)',
    '2': 'Variable {{2}} (Meta)',
    '3': 'Variable {{3}} (Meta)',
    '4': 'Variable {{4}} (Meta)',
    '5': 'Variable {{5}} (Meta)',
    name: 'Employee name ({{name}})',
    employee_name: 'Employee name ({{employee_name}})',
    document_type: 'Document type ({{document_type}})',
    expiry_date: 'Expiry date ({{expiry_date}})',
};

type WhatsAppDocumentTemplatePreviewProps = {
    templateName: string;
    templateLanguage: string;
    bodyText: string;
    headerType?: WhatsAppTemplateHeaderType;
    headerText?: string;
    sampleFileName?: string;
    className?: string;
};

export function WhatsAppDocumentTemplatePreview({
    templateName,
    templateLanguage,
    bodyText,
    headerType = 'document',
    headerText = '',
    sampleFileName = 'Employee Document.pdf',
    className,
}: WhatsAppDocumentTemplatePreviewProps) {
    const showDocumentHeader = headerType === 'document';
    const showTextHeader = headerType === 'text' && headerText.trim() !== '';

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
                        {showDocumentHeader ? (
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
                        ) : null}

                        {showTextHeader ? (
                            <div className="border-b border-white/10 bg-[#025c4b] px-3 py-2 text-sm font-semibold text-[#e9edef]">
                                {headerText}
                            </div>
                        ) : null}

                        <div className="px-3 py-2.5 text-sm leading-relaxed whitespace-pre-wrap text-[#e9edef]">
                            {bodyText}
                        </div>
                        <div className="flex justify-end px-2 pb-1.5">
                            <span className="text-[10px] text-[#99bfb5]">12:00 PM</span>
                        </div>
                    </div>
                </div>
            </div>

            <p className="text-xs text-muted-foreground">
                Approximate preview only. WhatsApp delivers the approved Meta template (
                <span className="font-mono text-foreground/80">{templateName}</span>
                ). Meta fills {'{{1}}'}, {'{{2}}'}, {'{{3}}'} in order when sending — map sample
                values below to see how the message will look. Edit wording in Meta WhatsApp Manager,
                not here.
            </p>
        </div>
    );
}

export function defaultSampleForWhatsAppVariable(key: string): string {
    const normalized = key.toLowerCase();

    return DEFAULT_PREVIEW_VARIABLES[normalized] ?? `Sample ${key}`;
}

export function labelForWhatsAppVariable(key: string): string {
    const normalized = key.toLowerCase();

    return VARIABLE_LABELS[normalized] ?? `Preview for {{${key}}}`;
}

export function extractWhatsAppTemplateVariables(bodyTemplate: string): string[] {
    const matches = bodyTemplate.matchAll(/\{\{(\d+|[a-z_]+)\}\}/gi);
    const keys: string[] = [];

    for (const match of matches) {
        const key = match[1]?.toLowerCase();

        if (key && !keys.includes(key)) {
            keys.push(key);
        }
    }

    return keys;
}

export function buildWhatsAppTemplatePreviewVariables(
    overrides: WhatsAppTemplatePreviewVariables = {},
): WhatsAppTemplatePreviewVariables {
    return {
        ...DEFAULT_PREVIEW_VARIABLES,
        ...overrides,
    };
}

export function renderWhatsAppTemplatePreviewBody(
    bodyTemplate: string,
    variables: WhatsAppTemplatePreviewVariables | string = {},
): string {
    const resolved =
        typeof variables === 'string'
            ? buildWhatsAppTemplatePreviewVariables({
                  name: variables.trim() !== '' ? variables.trim() : 'Employee Name',
                  '2': variables.trim() !== '' ? variables.trim() : 'Employee Name',
                  employee_name: variables.trim() !== '' ? variables.trim() : 'Employee Name',
              })
            : buildWhatsAppTemplatePreviewVariables(variables);

    return bodyTemplate.replace(/\{\{(\d+|[a-z_]+)\}\}/gi, (match, key: string) => {
        const normalized = key.toLowerCase();

        return resolved[normalized] ?? match;
    });
}
