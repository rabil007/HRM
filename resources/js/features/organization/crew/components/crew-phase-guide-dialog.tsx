import { Info } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import {
    CREW_PHASE_GUIDE_FOOTNOTE,
    crewPhaseGuideCompatibilityItems,
    crewPhaseGuideFlowItems,
} from '@/features/organization/crew/lib/crew-phase-guide-content';

function PhaseGuideCard({
    item,
}: {
    item: ReturnType<typeof crewPhaseGuideFlowItems>[number];
}): ReactElement {
    return (
        <article className="rounded-lg border border-border/70 bg-card/80 p-3">
            <div className="flex flex-wrap items-center gap-2">
                <CrewPhaseBadge code={item.code} label={item.label} />
            </div>
            <p className="mt-2 text-sm text-foreground">{item.description}</p>
            <p className="mt-2 text-xs text-muted-foreground">
                <span className="font-medium text-foreground">
                    Usually next:
                </span>{' '}
                {item.usuallyNext}
            </p>
            {item.alternativePath ? (
                <p className="mt-1 text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">
                        Other path:
                    </span>{' '}
                    {item.alternativePath}
                </p>
            ) : null}
            {item.compatibilityNote ? (
                <p className="mt-2 rounded-md border border-amber-500/25 bg-amber-500/5 px-2 py-1.5 text-[11px] text-amber-950 dark:text-amber-100">
                    {item.compatibilityNote}
                </p>
            ) : null}
        </article>
    );
}

export function CrewPhaseGuideDialog({
    triggerClassName,
}: {
    triggerClassName?: string;
}): ReactElement {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className={triggerClassName}
                    aria-label="Open crew movement phase guide"
                >
                    <Info className="size-4" aria-hidden="true" />
                    Phase Guide
                </Button>
            </DialogTrigger>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
                <DialogHeader className="border-b border-border/60 px-6 py-4 text-left">
                    <DialogTitle>Crew Movement Phases</DialogTitle>
                    <DialogDescription>
                        Educational reference for how crew mobilisation phases
                        relate to normal operational movement.
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 space-y-4 overflow-y-auto px-6 py-4">
                    <div className="flex flex-wrap items-center gap-1 text-[11px] font-medium text-muted-foreground">
                        {crewPhaseGuideFlowItems().map((item, index) => (
                            <span
                                key={item.code}
                                className="inline-flex items-center gap-1"
                            >
                                {index > 0 ? (
                                    <span aria-hidden="true">→</span>
                                ) : null}
                                <span>{item.code.toUpperCase()}</span>
                            </span>
                        ))}
                    </div>
                    <div className="grid gap-3">
                        {crewPhaseGuideFlowItems().map((item) => (
                            <PhaseGuideCard key={item.code} item={item} />
                        ))}
                    </div>
                    <div className="space-y-2">
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Compatibility phases
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {crewPhaseGuideCompatibilityItems().map((item) => (
                                <PhaseGuideCard key={item.code} item={item} />
                            ))}
                        </div>
                    </div>
                    <p className="rounded-md border border-border/60 bg-muted/20 px-3 py-2 text-xs text-muted-foreground">
                        {CREW_PHASE_GUIDE_FOOTNOTE}
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}
