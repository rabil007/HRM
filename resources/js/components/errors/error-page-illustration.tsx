import type { LucideIcon } from 'lucide-react';

export function ErrorPageIllustration({
    icon: Icon,
    label,
}: {
    icon: LucideIcon;
    label: string;
}) {
    return (
        <div
            className="mx-auto flex size-20 items-center justify-center rounded-2xl border border-border/80 bg-muted/40 text-primary shadow-sm dark:border-white/10 dark:bg-white/5"
            aria-hidden="true"
        >
            <Icon className="size-9" strokeWidth={1.75} />
            <span className="sr-only">{label}</span>
        </div>
    );
}
