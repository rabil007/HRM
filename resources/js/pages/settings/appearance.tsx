import { Head } from '@inertiajs/react';
import { Lock, Moon } from 'lucide-react';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance" />
            <h1 className="sr-only">Appearance settings</h1>

            {/* Page header */}
            <div className="mb-10 flex flex-col gap-2">
                <div className="flex items-center gap-2">
                    <span className="flex h-2 w-2 animate-pulse rounded-full bg-primary" />
                    <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                        Settings
                    </span>
                </div>
                <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">
                    Appearance
                </h1>
                <p className="text-sm font-medium text-muted-foreground/80">
                    Choose how the interface looks and feels across your
                    sessions.
                </p>
            </div>

            {/* Locked notice */}
            <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/20 px-4 py-3 dark:border-white/5 dark:bg-white/[0.03]">
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10">
                    <Moon className="h-4 w-4 text-primary" />
                </div>
                <div className="flex-1">
                    <p className="text-sm font-semibold">Dark mode</p>
                    <p className="text-xs text-muted-foreground/60">
                        The theme is currently fixed to dark mode by your
                        administrator.
                    </p>
                </div>
                <Lock className="h-4 w-4 shrink-0 text-muted-foreground/30" />
            </div>
        </>
    );
}

Appearance.layout = {};
