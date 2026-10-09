import { ChevronDown } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

export function CrewAssignmentCollapsibleRecord({
    title,
    summary,
    badges,
    defaultOpen = false,
    children,
    className,
    titleClassName,
    headerClassName,
    contentClassName,
    'data-slot': dataSlot,
}: {
    title: string;
    summary?: ReactNode;
    badges?: ReactNode;
    defaultOpen?: boolean;
    children: ReactNode;
    className?: string;
    titleClassName?: string;
    headerClassName?: string;
    contentClassName?: string;
    'data-slot'?: string;
}): ReactElement {
    return (
        <Collapsible defaultOpen={defaultOpen} data-slot={dataSlot}>
            <Card
                className={cn(
                    'border-border/80 dark:border-white/10',
                    className,
                )}
            >
                <CollapsibleTrigger
                    type="button"
                    className="group w-full text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <CardHeader
                        className={cn(
                            'flex flex-row items-start justify-between gap-3 pb-3',
                            headerClassName,
                        )}
                    >
                        <div className="min-w-0 space-y-1">
                            <CardTitle
                                className={cn('text-base', titleClassName)}
                            >
                                {title}
                            </CardTitle>
                            {summary ? (
                                <div className="text-xs text-muted-foreground">
                                    {summary}
                                </div>
                            ) : null}
                            {badges ? (
                                <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                                    {badges}
                                </div>
                            ) : null}
                        </div>
                        <ChevronDown
                            className="mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180"
                            aria-hidden
                        />
                    </CardHeader>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <CardContent className={cn('pt-0', contentClassName)}>
                        {children}
                    </CardContent>
                </CollapsibleContent>
            </Card>
        </Collapsible>
    );
}

export function recordStatusBadge(
    label: string,
    variant: 'warning' | 'secondary' | 'outline' = 'outline',
): ReactElement {
    return (
        <Badge variant={variant} className="font-medium">
            {label}
        </Badge>
    );
}
