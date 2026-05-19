import type { LucideIcon } from 'lucide-react';
import type { MouseEvent, ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type TableRowActionItem = {
    label: string;
    icon: LucideIcon;
    onClick?: (event: MouseEvent<HTMLButtonElement>) => void;
    href?: string;
    target?: string;
    rel?: string;
    variant?: 'default' | 'primary' | 'danger';
    hidden?: boolean;
};

function ghostIconClass(variant?: TableRowActionItem['variant']): string {
    if (variant === 'danger') {
        return 'size-8 rounded-lg text-red-400/70 hover:bg-red-500/10 hover:text-red-400';
    }

    return 'size-8 rounded-lg text-zinc-400 hover:bg-white/10 hover:text-zinc-100';
}

type TableRowActionsProps = {
    actions: TableRowActionItem[];
    className?: string;
    align?: 'start' | 'end';
};

export function TableRowActions({
    actions,
    className,
    align = 'end',
}: TableRowActionsProps): ReactElement {
    const visible = actions.filter((action) => !action.hidden);

    if (visible.length === 0) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    return (
        <div
            className={cn(
                'inline-flex shrink-0 flex-nowrap items-center gap-0.5',
                align === 'end' ? 'justify-end' : 'justify-start',
                className,
            )}
            onClick={(event) => event.stopPropagation()}
            role="presentation"
        >
            {visible.map((action) => {
                const Icon = action.icon;
                const iconTint = ghostIconClass(action.variant);

                if (action.href) {
                    return (
                        <Button
                            key={action.label}
                            variant="ghost"
                            size="icon"
                            className={iconTint}
                            asChild
                        >
                            <a
                                href={action.href}
                                target={action.target ?? undefined}
                                rel={action.rel}
                                title={action.label}
                                aria-label={action.label}
                            >
                                <Icon className="size-4" />
                            </a>
                        </Button>
                    );
                }

                return (
                    <Button
                        key={action.label}
                        type="button"
                        variant="ghost"
                        size="icon"
                        className={iconTint}
                        title={action.label}
                        aria-label={action.label}
                        onClick={action.onClick}
                    >
                        <Icon className="size-4" />
                    </Button>
                );
            })}
        </div>
    );
}
