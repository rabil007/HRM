import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';

export function MovementImpactCard({
    title,
    description,
    destructive = false,
}: {
    title: string;
    description: string | string[];
    destructive?: boolean;
}): ReactElement {
    const items = Array.isArray(description) ? description : null;

    return (
        <div
            className={cn(
                'rounded-lg border p-3 text-sm',
                destructive
                    ? 'border-destructive/30 bg-destructive/10 text-destructive'
                    : 'border-border/80 bg-muted/30 text-foreground',
            )}
        >
            <p className="font-medium">{title}</p>
            {items ? (
                <ul className="mt-2 list-disc space-y-1 pl-4 text-muted-foreground">
                    {items.map((item) => (
                        <li
                            key={item}
                            className={
                                destructive ? 'text-destructive' : undefined
                            }
                        >
                            {item}
                        </li>
                    ))}
                </ul>
            ) : (
                <p
                    className={cn(
                        'mt-1',
                        destructive
                            ? 'text-destructive'
                            : 'text-muted-foreground',
                    )}
                >
                    {description}
                </p>
            )}
        </div>
    );
}
