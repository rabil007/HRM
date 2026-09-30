import { ChevronDown, Info, Sparkles } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { DOCUMENT_AI_SUPPORTED_TYPE_LABELS } from '@/features/organization/documents/lib/document-ai-review';
import { cn } from '@/lib/utils';

export function DocumentAiSupportedTypesInfo(): ReactElement {
    const [expanded, setExpanded] = useState(false);

    return (
        <div className="rounded-lg border border-border/70 bg-muted/20 px-3 py-2 text-xs text-muted-foreground">
            <button
                type="button"
                className="flex w-full items-start gap-2 text-left"
                onClick={() => setExpanded((current) => !current)}
                aria-expanded={expanded}
            >
                <Sparkles className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" />
                <span className="min-w-0 flex-1">
                    <span className="font-medium text-foreground">
                        Document AI
                    </span>
                    <span className="text-muted-foreground">
                        {' '}
                        · Supports common employee documents
                    </span>
                </span>
                <span className="inline-flex shrink-0 items-center gap-1 text-primary">
                    View supported types
                    <ChevronDown
                        className={cn(
                            'h-3.5 w-3.5 transition-transform',
                            expanded && 'rotate-180',
                        )}
                    />
                </span>
            </button>
            {expanded ? (
                <div className="mt-2 space-y-2 border-t border-border/60 pt-2 pl-5">
                    <p className="leading-relaxed">
                        {DOCUMENT_AI_SUPPORTED_TYPE_LABELS.join(' · ')}
                    </p>
                    <p className="flex gap-1.5 leading-relaxed">
                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>
                            Document AI can also attempt to identify other
                            files. Unsupported or uncertain documents will
                            remain available for manual entry. For best results,
                            upload a clear PDF or image.
                        </span>
                    </p>
                </div>
            ) : null}
        </div>
    );
}
