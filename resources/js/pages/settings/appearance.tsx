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
            <div className="w-full h-full bg-white rounded-lg overflow-hidden flex flex-col">
                {/* Navbar */}
                <div className="h-7 bg-slate-100 border-b border-slate-200 flex items-center gap-1.5 px-2.5">
                    <div className="w-2 h-2 rounded-full bg-red-400" />
                    <div className="w-2 h-2 rounded-full bg-amber-400" />
                    <div className="w-2 h-2 rounded-full bg-emerald-400" />
                </div>
                <div className="flex flex-1 overflow-hidden">
                    {/* Sidebar */}
                    <div className="w-10 bg-slate-50 border-r border-slate-200 p-1.5 space-y-1.5">
                        <div className="w-full h-1.5 rounded-sm bg-primary/60" />
                        <div className="w-full h-1.5 rounded-sm bg-slate-200" />
                        <div className="w-full h-1.5 rounded-sm bg-slate-200" />
                        <div className="w-full h-1.5 rounded-sm bg-slate-200" />
                    </div>
                    {/* Content */}
                    <div className="flex-1 p-2 space-y-2">
                        <div className="h-2 w-3/4 rounded-sm bg-slate-200" />
                        <div className="h-1.5 w-full rounded-sm bg-slate-100" />
                        <div className="h-1.5 w-5/6 rounded-sm bg-slate-100" />
                        <div className="mt-2 h-8 rounded-md bg-slate-100 border border-slate-200" />
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
            <div className="w-full h-full bg-zinc-900 rounded-lg overflow-hidden flex flex-col">
                {/* Navbar */}
                <div className="h-7 bg-zinc-800 border-b border-white/5 flex items-center gap-1.5 px-2.5">
                    <div className="w-2 h-2 rounded-full bg-red-500/70" />
                    <div className="w-2 h-2 rounded-full bg-amber-500/70" />
                    <div className="w-2 h-2 rounded-full bg-emerald-500/70" />
                </div>
                <div className="flex flex-1 overflow-hidden">
                    {/* Sidebar */}
                    <div className="w-10 bg-zinc-800/80 border-r border-white/5 p-1.5 space-y-1.5">
                        <div className="w-full h-1.5 rounded-sm bg-primary/80" />
                        <div className="w-full h-1.5 rounded-sm bg-white/10" />
                        <div className="w-full h-1.5 rounded-sm bg-white/10" />
                        <div className="w-full h-1.5 rounded-sm bg-white/10" />
                    </div>
                    {/* Content */}
                    <div className="flex-1 p-2 space-y-2">
                        <div className="h-2 w-3/4 rounded-sm bg-white/15" />
                        <div className="h-1.5 w-full rounded-sm bg-white/8" />
                        <div className="h-1.5 w-5/6 rounded-sm bg-white/8" />
                        <div className="mt-2 h-8 rounded-md bg-white/5 border border-white/10" />
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
            <div className="w-full h-full rounded-lg overflow-hidden flex">
                {/* Left half — light */}
                <div className="w-1/2 bg-white flex flex-col overflow-hidden">
                    <div className="h-7 bg-slate-100 border-b border-slate-200" />
                    <div className="flex flex-1 overflow-hidden">
                        <div className="w-8 bg-slate-50 border-r border-slate-200 p-1 space-y-1">
                            <div className="w-full h-1.5 rounded-sm bg-primary/60" />
                            <div className="w-full h-1.5 rounded-sm bg-slate-200" />
                        </div>
                        <div className="flex-1 p-1.5 space-y-1.5">
                            <div className="h-1.5 w-3/4 rounded-sm bg-slate-200" />
                            <div className="h-1.5 rounded-sm bg-slate-100" />
                        </div>
                    </div>
                </div>
                {/* Divider */}
                <div className="w-px bg-gradient-to-b from-transparent via-white/30 to-transparent" />
                {/* Right half — dark */}
                <div className="w-1/2 bg-zinc-900 flex flex-col overflow-hidden">
                    <div className="h-7 bg-zinc-800 border-b border-white/5" />
                    <div className="flex flex-1 overflow-hidden">
                        <div className="w-8 bg-zinc-800/80 border-r border-white/5 p-1 space-y-1">
                            <div className="w-full h-1.5 rounded-sm bg-primary/70" />
                            <div className="w-full h-1.5 rounded-sm bg-white/10" />
                        </div>
                        <div className="flex-1 p-1.5 space-y-1.5">
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
    const { appearance, resolvedAppearance, updateAppearance } = useAppearance();

    return (
        <>
            <Head title="Appearance" />
            <h1 className="sr-only">Appearance settings</h1>

            {/* Page header */}
            <div className="flex flex-col gap-2 mb-10">
                <div className="flex items-center gap-2">
                    <span className="flex h-2 w-2 rounded-full bg-primary animate-pulse" />
                    <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                        Settings
                    </span>
                </div>
                <h1 className="text-4xl font-extrabold tracking-tight bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-transparent">
                    Appearance
                </h1>
                <p className="text-sm text-muted-foreground/80 font-medium">
                    Choose how the interface looks and feels across your sessions.
                </p>
            </div>

            {/* Live indicator banner */}
            <div className="mb-8 flex items-center gap-3 px-4 py-3 rounded-xl border border-white/5 bg-white/[0.03]">
                <div className="flex items-center gap-2 shrink-0">
                    {resolvedAppearance === 'dark' ? (
                        <Moon className="w-4 h-4 text-primary" />
                    ) : (
                        <Sun className="w-4 h-4 text-amber-400" />
                    )}
                    <span className="text-xs font-semibold text-foreground">
                        Currently:{' '}
                        <span className="text-primary capitalize">{resolvedAppearance}</span> mode
                    </span>
                </div>
                <div className="flex-1 h-px bg-white/5" />
                <span className="text-[10px] text-muted-foreground/50">
                    {appearance === 'system'
                        ? 'Following OS preference'
                        : 'Manually selected · stored in browser'}
                </span>
            </div>

            {/* Mode selector cards */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-5">
                {MODES.map(({ value, label, description, icon: Icon, preview }) => {
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
                                    ? 'border-primary/40 shadow-lg shadow-primary/10 ring-1 ring-primary/20 bg-primary/5'
                                    : 'border-white/5 bg-white/[0.03] hover:border-white/10 hover:bg-white/[0.05]',
                            )}
                        >
                            {/* Preview window */}
                            <div className="relative h-36 w-full p-3 bg-black/20">
                                <div className="w-full h-full rounded-lg overflow-hidden shadow-xl border border-white/10">
                                    {preview}
                                </div>

                                {/* Active check badge */}
                                {isActive ? (
                                    <div className="absolute top-4 right-4 w-6 h-6 rounded-full bg-primary flex items-center justify-center shadow-lg shadow-primary/30">
                                        <CheckCircle2 className="w-3.5 h-3.5 text-primary-foreground" />
                                    </div>
                                ) : null}
                            </div>

                            {/* Label row */}
                            <div className="flex items-center gap-3 px-4 py-4 border-t border-white/5">
                                <div
                                    className={cn(
                                        'w-8 h-8 rounded-xl border flex items-center justify-center shrink-0 transition-colors',
                                        isActive
                                            ? 'bg-primary/10 border-primary/30 text-primary'
                                            : 'bg-white/5 border-white/5 text-muted-foreground',
                                    )}
                                >
                                    <Icon className="w-4 h-4" />
                                </div>
                                <div className="min-w-0">
                                    <p
                                        className={cn(
                                            'text-sm font-bold tracking-tight transition-colors',
                                            isActive ? 'text-primary' : 'text-foreground',
                                        )}
                                    >
                                        {label}
                                    </p>
                                    <p className="text-[10px] text-muted-foreground/60 leading-snug mt-0.5">
                                        {description}
                                    </p>
                                </div>
                            </div>
                        </button>
                    );
                })}
            </div>
        </>
    );
}

Appearance.layout = {};
