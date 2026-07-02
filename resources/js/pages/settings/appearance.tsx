import { Head } from '@inertiajs/react';
import { CheckCircle2, Monitor, Moon, Sun } from 'lucide-react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

const MODES: {
    value: Appearance;
    label: string;
    description: string;
    icon: React.ComponentType<{ className?: string }>;
    preview: React.ReactNode;
}[] = [
    {
        value: 'light',
        label: 'Light',
        description: 'Clean white background. Best for bright environments.',
        icon: Sun,
        preview: (
            <div className="flex h-full w-full flex-col overflow-hidden rounded-lg bg-white">
                {/* Navbar */}
                <div className="flex h-7 items-center gap-1.5 border-b border-slate-200 bg-slate-100 px-2.5">
                    <div className="h-2 w-2 rounded-full bg-red-400" />
                    <div className="h-2 w-2 rounded-full bg-amber-400" />
                    <div className="h-2 w-2 rounded-full bg-emerald-400" />
                </div>
                <div className="flex flex-1 overflow-hidden">
                    {/* Sidebar */}
                    <div className="w-10 space-y-1.5 border-r border-slate-200 bg-slate-50 p-1.5">
                        <div className="h-1.5 w-full rounded-sm bg-primary/60" />
                        <div className="h-1.5 w-full rounded-sm bg-slate-200" />
                        <div className="h-1.5 w-full rounded-sm bg-slate-200" />
                        <div className="h-1.5 w-full rounded-sm bg-slate-200" />
                    </div>
                    {/* Content */}
                    <div className="flex-1 space-y-2 p-2">
                        <div className="h-2 w-3/4 rounded-sm bg-slate-200" />
                        <div className="h-1.5 w-full rounded-sm bg-slate-100" />
                        <div className="h-1.5 w-5/6 rounded-sm bg-slate-100" />
                        <div className="mt-2 h-8 rounded-md border border-slate-200 bg-slate-100" />
                    </div>
                </div>
            </div>
        ),
    },
    {
        value: 'dark',
        label: 'Dark',
        description: 'Elegant dark theme. Easy on the eyes in low-light.',
        icon: Moon,
        preview: (
            <div className="flex h-full w-full flex-col overflow-hidden rounded-lg bg-zinc-900">
                {/* Navbar */}
                <div className="flex h-7 items-center gap-1.5 border-b border-white/5 bg-zinc-800 px-2.5">
                    <div className="h-2 w-2 rounded-full bg-red-500/70" />
                    <div className="h-2 w-2 rounded-full bg-amber-500/70" />
                    <div className="h-2 w-2 rounded-full bg-emerald-500/70" />
                </div>
                <div className="flex flex-1 overflow-hidden">
                    {/* Sidebar */}
                    <div className="w-10 space-y-1.5 border-r border-white/5 bg-zinc-800/80 p-1.5">
                        <div className="h-1.5 w-full rounded-sm bg-primary/80" />
                        <div className="h-1.5 w-full rounded-sm bg-white/10" />
                        <div className="h-1.5 w-full rounded-sm bg-white/10" />
                        <div className="h-1.5 w-full rounded-sm bg-white/10" />
                    </div>
                    {/* Content */}
                    <div className="flex-1 space-y-2 p-2">
                        <div className="h-2 w-3/4 rounded-sm bg-white/15" />
                        <div className="h-1.5 w-full rounded-sm bg-white/8" />
                        <div className="h-1.5 w-5/6 rounded-sm bg-white/8" />
                        <div className="mt-2 h-8 rounded-md border border-white/10 bg-white/5" />
                    </div>
                </div>
            </div>
        ),
    },
    {
        value: 'system',
        label: 'System',
        description: 'Automatically follows your OS dark/light preference.',
        icon: Monitor,
        preview: (
            <div className="flex h-full w-full overflow-hidden rounded-lg">
                {/* Left half — light */}
                <div className="flex w-1/2 flex-col overflow-hidden bg-white">
                    <div className="h-7 border-b border-slate-200 bg-slate-100" />
                    <div className="flex flex-1 overflow-hidden">
                        <div className="w-8 space-y-1 border-r border-slate-200 bg-slate-50 p-1">
                            <div className="h-1.5 w-full rounded-sm bg-primary/60" />
                            <div className="h-1.5 w-full rounded-sm bg-slate-200" />
                        </div>
                        <div className="flex-1 space-y-1.5 p-1.5">
                            <div className="h-1.5 w-3/4 rounded-sm bg-slate-200" />
                            <div className="h-1.5 rounded-sm bg-slate-100" />
                        </div>
                    </div>
                </div>
                {/* Divider */}
                <div className="w-px bg-gradient-to-b from-transparent via-white/30 to-transparent" />
                {/* Right half — dark */}
                <div className="flex w-1/2 flex-col overflow-hidden bg-zinc-900">
                    <div className="h-7 border-b border-white/5 bg-zinc-800" />
                    <div className="flex flex-1 overflow-hidden">
                        <div className="w-8 space-y-1 border-r border-white/5 bg-zinc-800/80 p-1">
                            <div className="h-1.5 w-full rounded-sm bg-primary/70" />
                            <div className="h-1.5 w-full rounded-sm bg-white/10" />
                        </div>
                        <div className="flex-1 space-y-1.5 p-1.5">
                            <div className="h-1.5 w-3/4 rounded-sm bg-white/15" />
                            <div className="h-1.5 rounded-sm bg-white/8" />
                        </div>
                    </div>
                </div>
            </div>
        ),
    },
];

export default function Appearance() {
    const { appearance, resolvedAppearance, updateAppearance } =
        useAppearance();

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

            {/* Live indicator banner */}
            <div className="mb-8 flex items-center gap-3 rounded-xl border border-border/80 bg-muted/20 px-4 py-3 dark:border-white/5 dark:bg-white/[0.03]">
                <div className="flex shrink-0 items-center gap-2">
                    {resolvedAppearance === 'dark' ? (
                        <Moon className="h-4 w-4 text-primary" />
                    ) : (
                        <Sun className="h-4 w-4 text-amber-400" />
                    )}
                    <span className="text-xs font-semibold text-foreground">
                        Currently:{' '}
                        <span className="text-primary capitalize">
                            {resolvedAppearance}
                        </span>{' '}
                        mode
                    </span>
                </div>
                <div className="h-px flex-1 bg-border/80 dark:bg-white/5" />
                <span className="text-[10px] text-muted-foreground/50">
                    {appearance === 'system'
                        ? 'Following OS preference'
                        : 'Manually selected · stored in browser'}
                </span>
            </div>

            {/* Mode selector cards */}
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                {MODES.map(
                    ({ value, label, description, icon: Icon, preview }) => {
                        const isActive = appearance === value;

                        return (
                            <button
                                key={value}
                                type="button"
                                id={`appearance-${value}`}
                                onClick={() => updateAppearance(value)}
                                className={cn(
                                    'group relative flex flex-col overflow-hidden rounded-2xl border text-left transition-all duration-300',
                                    isActive
                                        ? 'border-primary/40 bg-primary/5 shadow-lg ring-1 shadow-primary/10 ring-primary/20'
                                        : 'border-border/80 bg-muted/20 hover:border-border hover:bg-muted/40 dark:border-white/5 dark:bg-white/[0.03] dark:hover:border-white/10 dark:hover:bg-white/[0.05]',
                                )}
                            >
                                {/* Preview window */}
                                <div className="relative h-36 w-full bg-black/20 p-3">
                                    <div className="h-full w-full overflow-hidden rounded-lg border border-border/60 shadow-xl dark:border-white/10">
                                        {preview}
                                    </div>

                                    {/* Active check badge */}
                                    {isActive ? (
                                        <div className="absolute top-4 right-4 flex h-6 w-6 items-center justify-center rounded-full bg-primary shadow-lg shadow-primary/30">
                                            <CheckCircle2 className="h-3.5 w-3.5 text-primary-foreground" />
                                        </div>
                                    ) : null}
                                </div>

                                {/* Label row */}
                                <div className="flex items-center gap-3 border-t border-border/80 px-4 py-4 dark:border-white/5">
                                    <div
                                        className={cn(
                                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-xl border transition-colors',
                                            isActive
                                                ? 'border-primary/30 bg-primary/10 text-primary'
                                                : 'border-border bg-muted/40 text-muted-foreground dark:border-white/5 dark:bg-white/5',
                                        )}
                                    >
                                        <Icon className="h-4 w-4" />
                                    </div>
                                    <div className="min-w-0">
                                        <p
                                            className={cn(
                                                'text-sm font-bold tracking-tight transition-colors',
                                                isActive
                                                    ? 'text-primary'
                                                    : 'text-foreground',
                                            )}
                                        >
                                            {label}
                                        </p>
                                        <p className="mt-0.5 text-[10px] leading-snug text-muted-foreground/60">
                                            {description}
                                        </p>
                                    </div>
                                </div>
                            </button>
                        );
                    },
                )}
            </div>
        </>
    );
}

Appearance.layout = {};
