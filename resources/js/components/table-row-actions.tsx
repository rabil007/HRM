import type { MouseEvent, ReactElement } from 'react';
import { cn } from '@/lib/utils';

export type TableRowActionItem = {
    label: string;
    onClick?: (event: MouseEvent<HTMLButtonElement>) => void;
    href?: string;
    target?: string;
    rel?: string;
    variant?: 'default' | 'primary' | 'danger';
    hidden?: boolean;
};

const actionVariantClass: Record<NonNullable<TableRowActionItem['variant']>, string> = {
    default: 'text-xs text-zinc-400 transition-colors hover:text-zinc-200',
    primary: 'text-xs font-semibold text-primary transition-colors hover:underline',
    danger: 'text-xs text-red-400/60 transition-colors hover:text-red-400',
};

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
        return <span className="text-xs text-zinc-600">—</span>;
    }

    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-2',
                align === 'end' ? 'justify-end' : 'justify-start',
                className,
            )}
            onClick={(event) => event.stopPropagation()}
            role="presentation"
        >
            {visible.map((action) => {
                const classNameForAction = actionVariantClass[action.variant ?? 'default'];

                if (action.href) {
                    return (
                        <a
                            key={action.label}
                            href={action.href}
                            target={action.target}
                            rel={action.rel}
                            className={classNameForAction}
                        >
                            {action.label}
                        </a>
                    );
                }

                return (
                    <button
                        key={action.label}
                        type="button"
                        className={classNameForAction}
                        onClick={action.onClick}
                    >
                        {action.label}
                    </button>
                );
            })}
        </div>
    );
}
