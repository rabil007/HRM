import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const PHASE_CODE_STYLES: Record<string, string> = {
    p0: 'border-muted-foreground/30 bg-muted/50 text-muted-foreground',
    p1: 'border-info/30 bg-info/10 text-info',
    p2a: 'border-warning/30 bg-warning/10 text-warning',
    p2b: 'border-warning/30 bg-warning/10 text-warning',
    p3: 'border-primary/30 bg-primary/10 text-primary',
    p4: 'border-success/30 bg-success/10 text-success',
    p5: 'border-warning/30 bg-warning/10 text-warning',
    p6: 'border-muted-foreground/30 bg-muted/50 text-muted-foreground',
};

const STATUS_STYLES: Record<string, string> = {
    planned: 'opacity-80',
    active: 'ring-1 ring-current/20',
    completed: 'opacity-70',
    cancelled: 'border-destructive/30 bg-destructive/10 text-destructive',
    corrected: 'border-muted-foreground/30 bg-muted/40 text-muted-foreground',
};

export function CrewPhaseBadge({
    code,
    label,
    status,
}: {
    code: string;
    label: string;
    status?: string;
}): ReactElement {
    const codeKey = code.toLowerCase();

    return (
        <Badge
            variant="outline"
            className={cn(
                'font-medium',
                PHASE_CODE_STYLES[codeKey] ?? 'border-border bg-muted/30 text-foreground',
                status ? STATUS_STYLES[status] : undefined,
            )}
            title={label}
        >
            <span className="font-semibold uppercase">{codeKey}</span>
            <span aria-hidden="true">·</span>
            <span>{label}</span>
        </Badge>
    );
}
