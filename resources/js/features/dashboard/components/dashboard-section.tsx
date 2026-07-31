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
            <div className="flex items-center justify-between border-b border-border/40 pb-3">
                <div className="flex items-center gap-2">
                    {Icon && <Icon className="h-5 w-5 text-primary" />}
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight text-foreground">
                            {title}
                        </h2>
                        {description && (
                            <p className="text-xs text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                </div>

                {actionLabel && actionHref && (
                    <Button asChild variant="ghost" size="sm" className="h-8 text-xs gap-1 font-medium">
                        <Link href={actionHref}>
                            {actionLabel}
                        </Link>
                    </Button>
                )}
            </div>

            {children}
        </section>
    );
}
