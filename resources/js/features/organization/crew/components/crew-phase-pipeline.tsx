import { ArrowRight, Route } from 'lucide-react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type CrewPhasePipelineItem = {
    code: string;
    label: string;
    count: number;
};

const PHASE_TONES: Record<string, string> = {
    p0: 'bg-sky-500',
    p1: 'bg-cyan-500',
    p2a: 'bg-indigo-500',
    p2b: 'bg-violet-500',
    p3: 'bg-blue-500',
    p4: 'bg-emerald-500',
    p5: 'bg-amber-500',
    p6: 'bg-slate-500',
};

export function CrewPhasePipeline({
    phases,
    total,
    activePhase,
    onSelect,
}: {
    phases: CrewPhasePipelineItem[];
    total: number;
    activePhase: string;
    onSelect: (phase: string) => void;
}): ReactElement {
    return (
        <section
            className="overflow-hidden rounded-xl border border-border/60 bg-card/60 dark:border-white/5 dark:bg-white/1"
            aria-labelledby="crew-phase-pipeline"
        >
            <div className="flex flex-col gap-2 border-b border-border/50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/5">
                <div>
                    <h2
                        id="crew-phase-pipeline"
                        className="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <Route className="size-4 text-primary" />
                        Assignment pipeline
                    </h2>
                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                        Select a phase to filter the operating board
                    </p>
                </div>
                <Badge variant="outline" className="w-fit rounded-full">
                    {total} active assignments
                </Badge>
            </div>

            <div className="overflow-x-auto px-3 py-4">
                <div className="grid min-w-[960px] grid-cols-8">
                    {phases.map((phase, index) => {
                        const isActive = activePhase === phase.code;
                        const share =
                            total > 0
                                ? Math.round((phase.count / total) * 100)
                                : 0;

                        return (
                            <div
                                key={phase.code}
                                className="relative flex items-stretch"
                            >
                                {index < phases.length - 1 ? (
                                    <div className="pointer-events-none absolute top-[17px] left-[calc(50%+16px)] z-0 flex w-[calc(100%-32px)] items-center">
                                        <span className="h-px flex-1 bg-border dark:bg-white/10" />
                                        <ArrowRight className="size-3 text-muted-foreground/40" />
                                    </div>
                                ) : null}
                                <button
                                    type="button"
                                    onClick={() =>
                                        onSelect(isActive ? '' : phase.code)
                                    }
                                    aria-pressed={isActive}
                                    className={cn(
                                        'group relative z-10 mx-1 flex min-w-0 flex-1 flex-col items-center rounded-lg px-2 py-1.5 text-center transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        isActive
                                            ? 'bg-primary/8'
                                            : 'hover:bg-muted/40',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex size-8 items-center justify-center rounded-full border-4 border-background text-[10px] font-bold text-white shadow-sm ring-1 ring-border/50 transition-transform group-hover:scale-105 dark:border-background',
                                            PHASE_TONES[phase.code] ??
                                                'bg-muted-foreground',
                                            isActive &&
                                                'ring-2 ring-primary ring-offset-2 ring-offset-background',
                                        )}
                                    >
                                        {phase.count}
                                    </span>
                                    <span className="mt-2 text-[10px] font-bold tracking-wide text-muted-foreground uppercase">
                                        {phase.code}
                                    </span>
                                    <span className="mt-0.5 line-clamp-1 max-w-full text-[11px] font-medium text-foreground/80">
                                        {phase.label}
                                    </span>
                                    <span className="mt-1 text-[10px] text-muted-foreground/60 tabular-nums">
                                        {share}%
                                    </span>
                                </button>
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
