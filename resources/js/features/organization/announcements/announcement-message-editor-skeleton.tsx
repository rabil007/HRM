import type { ReactElement } from 'react';

/**
 * Mirrors the frame, toolbar height, and body height of
 * AnnouncementMessageEditor so lazy-loading it causes no layout shift.
 */
export function AnnouncementMessageEditorSkeleton(): ReactElement {
    return (
        <div className="overflow-hidden rounded-xl border border-input bg-background shadow-xs">
            <div className="flex flex-wrap items-center gap-1 border-b border-border/70 bg-muted/30 p-2">
                {Array.from({ length: 10 }).map((_, index) => (
                    <div
                        key={index}
                        className="size-8 animate-pulse rounded-md bg-muted dark:bg-white/10"
                    />
                ))}
            </div>
            <div className="min-h-52 animate-pulse bg-muted/20" />
        </div>
    );
}
