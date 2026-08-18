import { Link } from '@inertiajs/react';
import { AlertTriangle, MoreVertical } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { joinMobileRecordMeta } from '@/lib/mobile-operational-list';
import { cn } from '@/lib/utils';

export type MobileRecordOverflowAction = {
    key: string;
    label: string;
    href?: string;
    target?: string;
    rel?: string;
    onSelect?: () => void;
    destructive?: boolean;
    disabled?: boolean;
};

export type MobileRecordPrimaryAction = {
    label: string;
    href?: string;
    onClick?: () => void;
    disabled?: boolean;
};

export function MobileRecordList({
    children,
    className,
    labelledBy,
}: {
    children: ReactNode;
    className?: string;
    labelledBy?: string;
}) {
    return (
        <ul
            className={cn('flex flex-col gap-2', className)}
            aria-labelledby={labelledBy}
        >
            {children}
        </ul>
    );
}

export function MobileRecordActions({
    actions,
    extra,
}: {
    actions: MobileRecordOverflowAction[];
    extra?: ReactNode;
}) {
    const visible = actions.filter((action) => action.label.trim() !== '');

    if (visible.length === 0 && extra == null) {
        return null;
    }

    return (
        <div
            className="flex shrink-0 items-center gap-0.5"
            onClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => event.stopPropagation()}
            role="presentation"
        >
            {extra}
            {visible.length > 0 ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-11 w-11 rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground"
                            aria-label="More actions"
                        >
                            <MoreVertical className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-48">
                        {visible.map((action) => {
                            const itemClass = cn(
                                action.destructive &&
                                    'text-destructive focus:text-destructive',
                            );

                            if (action.href) {
                                return (
                                    <DropdownMenuItem
                                        key={action.key}
                                        asChild
                                        disabled={action.disabled}
                                        className={itemClass}
                                    >
                                        <a
                                            href={action.href}
                                            target={action.target}
                                            rel={action.rel}
                                        >
                                            {action.label}
                                        </a>
                                    </DropdownMenuItem>
                                );
                            }

                            return (
                                <DropdownMenuItem
                                    key={action.key}
                                    disabled={action.disabled}
                                    className={itemClass}
                                    onSelect={() => action.onSelect?.()}
                                >
                                    {action.label}
                                </DropdownMenuItem>
                            );
                        })}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}
        </div>
    );
}

export function MobileRecordCard({
    title,
    subtitle,
    meta,
    status,
    attention,
    href,
    primaryAction,
    overflowActions = [],
    extraActions,
    leading,
}: {
    title: string;
    subtitle?: string | null;
    meta?: Array<string | null | undefined>;
    status?: ReactNode;
    attention?: string | null;
    href?: string;
    primaryAction?: MobileRecordPrimaryAction;
    overflowActions?: MobileRecordOverflowAction[];
    extraActions?: ReactNode;
    leading?: ReactNode;
}) {
    const metaLine = joinMobileRecordMeta(meta ?? []);
    const resolvedPrimary =
        primaryAction ?? (href ? { label: 'Open', href } : undefined);
    const identity = (
        <>
            <h3 className="truncate text-sm leading-snug font-semibold text-foreground">
                {title}
            </h3>
            {subtitle ? (
                <p className="mt-0.5 truncate font-mono text-[11px] text-muted-foreground">
                    {subtitle}
                </p>
            ) : null}
        </>
    );

    return (
        <li>
            <article className="rounded-xl border border-border/60 bg-card/80 p-3 shadow-sm dark:border-white/8 dark:bg-card/60">
                <div className="flex items-start gap-2.5">
                    {leading ? (
                        <div className="mt-0.5 shrink-0">{leading}</div>
                    ) : null}

                    {href ? (
                        <Link
                            href={href}
                            className="min-w-0 flex-1 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                            aria-label={`Open ${title}`}
                        >
                            {identity}
                        </Link>
                    ) : (
                        <div className="min-w-0 flex-1">{identity}</div>
                    )}

                    <div className="flex shrink-0 items-start gap-1">
                        {status ? (
                            <div className="max-w-[9.5rem] pt-0.5 [&_*]:max-w-full [&_*]:truncate">
                                {status}
                            </div>
                        ) : null}
                        <MobileRecordActions
                            actions={overflowActions}
                            extra={extraActions}
                        />
                    </div>
                </div>

                {metaLine ? (
                    <p className="mt-1.5 truncate text-xs text-muted-foreground">
                        {metaLine}
                    </p>
                ) : null}

                {attention ? (
                    <p className="mt-2 flex items-start gap-1.5 text-[11px] text-amber-700 dark:text-amber-300">
                        <AlertTriangle
                            className="mt-0.5 h-3.5 w-3.5 shrink-0"
                            aria-hidden
                        />
                        <span className="min-w-0">{attention}</span>
                    </p>
                ) : null}

                {resolvedPrimary ? (
                    <div className="mt-2.5">
                        {resolvedPrimary.href ? (
                            <Button
                                asChild
                                variant="secondary"
                                size="sm"
                                className="h-10 min-h-10 rounded-lg px-3 text-xs"
                                disabled={resolvedPrimary.disabled}
                            >
                                <Link href={resolvedPrimary.href}>
                                    {resolvedPrimary.label}
                                </Link>
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                className="h-10 min-h-10 rounded-lg px-3 text-xs"
                                disabled={resolvedPrimary.disabled}
                                onClick={resolvedPrimary.onClick}
                            >
                                {resolvedPrimary.label}
                            </Button>
                        )}
                    </div>
                ) : null}
            </article>
        </li>
    );
}
