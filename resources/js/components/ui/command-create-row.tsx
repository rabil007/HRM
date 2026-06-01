import { Loader2, PlusIcon } from 'lucide-react';
import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';

type CommandCreateRowProps = {
    query: string;
    isCreating?: boolean;
    onCreate: () => void | Promise<void>;
    className?: string;
};

/**
 * Pinned "create new" action for cmdk lists. Render outside CommandList so filter
 * scoring does not push this row below partial matches (e.g. Create "ber" under "Berltiz").
 */
export function CommandCreateRow({
    query,
    isCreating = false,
    onCreate,
    className,
}: CommandCreateRowProps): ReactElement | null {
    const trimmed = query.trim();

    if (trimmed === '') {
        return null;
    }

    return (
        <div className={cn('border-t border-border/60 p-1', className)}>
            <button
                type="button"
                disabled={isCreating}
                onClick={() => {
                    void onCreate();
                }}
                className="relative flex w-full cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none text-primary hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50"
            >
                {isCreating ? (
                    <Loader2 className="size-4 shrink-0 animate-spin" />
                ) : (
                    <PlusIcon className="size-4 shrink-0" />
                )}
                <span className="flex-1 truncate text-left">
                    Create &quot;{trimmed}&quot;
                </span>
            </button>
        </div>
    );
}
