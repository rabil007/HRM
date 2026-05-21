import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type EmptyStateProps = {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: {
        label: string;
        onClick: () => void;
    };
    className?: string;
    children?: ReactNode;
};

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
    children,
}: EmptyStateProps) {
    return (
        <div className={cn('ds-empty-state', className)}>
            {Icon ? (
                <div className="mb-4 flex size-12 items-center justify-center rounded-xl border border-border/80 bg-muted/40 text-muted-foreground">
                    <Icon className="size-5" aria-hidden />
                </div>
            ) : null}
            <h3 className="text-sm font-semibold text-foreground">{title}</h3>
            {description ? (
                <p className="mt-1 max-w-sm text-sm text-muted-foreground">{description}</p>
            ) : null}
            {children}
            {action ? (
                <Button type="button" className="mt-4" size="sm" onClick={action.onClick}>
                    {action.label}
                </Button>
            ) : null}
        </div>
    );
}
