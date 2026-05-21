import type { ReactNode } from 'react';
import { typography } from '@/lib/design-system';
import { cn } from '@/lib/utils';

type SectionHeaderProps = {
    title: string;
    description?: string;
    actions?: ReactNode;
    className?: string;
};

export function SectionHeader({
    title,
    description,
    actions,
    className,
}: SectionHeaderProps) {
    return (
        <div
            className={cn(
                'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between',
                className,
            )}
        >
            <div className="space-y-0.5">
                <h2 className={typography.sectionTitle}>{title}</h2>
                {description ? (
                    <p className={typography.muted}>{description}</p>
                ) : null}
            </div>
            {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
        </div>
    );
}
