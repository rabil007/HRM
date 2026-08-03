import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

type DashboardSectionProps = {
    title: string;
    description?: string;
    icon?: LucideIcon;
    actionLabel?: string;
    actionHref?: string;
    children: ReactNode;
};

export function DashboardSection({
    title,
    description,
    icon: Icon,
    actionLabel,
    actionHref,
    children,
}: DashboardSectionProps) {
    return (
        <section className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div className="flex items-center gap-3">
                    {Icon && (
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <Icon className="h-4.5 w-4.5" />
                        </div>
                    )}
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight text-foreground sm:text-xl">
                            {title}
                        </h2>
                        {description && (
                            <p className="text-sm text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                </div>

                {actionLabel && actionHref && (
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="h-8 gap-1 self-start text-xs font-medium sm:self-auto"
                    >
                        <Link href={actionHref}>{actionLabel}</Link>
                    </Button>
                )}
            </div>

            {children}
        </section>
    );
}
