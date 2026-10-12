import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

export function CandidateWorkspaceSection({
    title,
    description,
    defaultOpen = true,
    children,
    className,
}: {
    title: string;
    description?: string;
    defaultOpen?: boolean;
    children: ReactNode;
    className?: string;
}) {
    if (defaultOpen) {
        return (
            <section className={cn('space-y-3', className)}>
                <div>
                    <h2 className="text-sm font-semibold tracking-wide uppercase">
                        {title}
                    </h2>
                    {description ? (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {description}
                        </p>
                    ) : null}
                </div>
                {children}
            </section>
        );
    }

    return (
        <Collapsible defaultOpen={false} className={cn('space-y-2', className)}>
            <CollapsibleTrigger className="flex w-full items-center justify-between rounded-lg border border-border/60 bg-muted/20 px-3 py-2 text-left text-sm font-medium hover:bg-muted/40">
                <span>
                    {title}
                    {description ? (
                        <span className="mt-0.5 block text-xs font-normal text-muted-foreground">
                            {description}
                        </span>
                    ) : null}
                </span>
                <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground" />
            </CollapsibleTrigger>
            <CollapsibleContent className="space-y-3 pt-1">
                {children}
            </CollapsibleContent>
        </Collapsible>
    );
}
