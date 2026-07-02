import type { LucideIcon } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type EmployeeSectionCardProps = {
    title: string;
    description?: string;
    icon?: LucideIcon;
    className?: string;
    bodyClassName?: string;
    children: ReactNode;
};

export function EmployeeSectionCard({
    title,
    description,
    icon: Icon,
    className,
    bodyClassName,
    children,
}: EmployeeSectionCardProps): ReactElement {
    return (
        <section
            className={cn(
                'flex h-full flex-col overflow-hidden rounded-2xl border border-border/80 bg-card/40 shadow-sm backdrop-blur-xl dark:border-white/[0.08] dark:bg-gradient-to-b dark:from-white/[0.07] dark:to-white/[0.02] dark:shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)]',
                className,
            )}
        >
            <header className="flex items-start gap-3 border-b border-border/60 px-5 py-4 dark:border-white/[0.06]">
                {Icon ? (
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-xl border border-border bg-muted/40 text-muted-foreground dark:bg-white/[0.04]">
                        <Icon className="size-4" aria-hidden />
                    </span>
                ) : null}
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold tracking-tight text-foreground">
                        {title}
                    </h3>
                    {description ? (
                        <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                            {description}
                        </p>
                    ) : null}
                </div>
            </header>
            <div
                className={cn(
                    'flex flex-1 flex-col gap-1 px-5 py-4',
                    bodyClassName,
                )}
            >
                {children}
            </div>
        </section>
    );
}
