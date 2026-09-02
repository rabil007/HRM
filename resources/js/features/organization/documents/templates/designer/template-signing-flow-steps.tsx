import { AlertTriangle, Check } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    signingStepPlacementCopy,
    workflowFocusKey,
} from '../lib/template-workflow';
import type { SigningStepPlacementStatus } from '../lib/template-workflow';

type Props = {
    presetName: string;
    steps: SigningStepPlacementStatus[];
    readOnly: boolean;
    highlightedSlotKey: string | null;
    focusedSlotKey: string | null;
    onLocateSlot: (slotKey: string) => void;
    onPlaceSlot: (slotKey: string, label: string) => void;
};

export function TemplateSigningFlowSteps({
    presetName,
    steps,
    readOnly,
    highlightedSlotKey,
    focusedSlotKey,
    onLocateSlot,
    onPlaceSlot,
}: Props) {
    return (
        <div className="space-y-2">
            <p className="truncate text-xs font-medium text-foreground">
                {presetName}
            </p>
            <ol className="space-y-1.5">
                {steps.map((step) => {
                    const selected = highlightedSlotKey === step.slotKey;
                    const focused = focusedSlotKey === step.slotKey;
                    const status = signingStepPlacementCopy(step.placed);

                    return (
                        <li
                            key={step.slotKey}
                            data-workflow-focus={workflowFocusKey({
                                section: 'signing-step',
                                slotKey: step.slotKey,
                            })}
                            className={cn(
                                'rounded-md border px-2 py-1.5',
                                selected
                                    ? 'border-primary bg-primary/8 shadow-xs'
                                    : 'border-border/70',
                                focused &&
                                    'motion-safe:ring-2 motion-safe:ring-primary/35',
                            )}
                        >
                            {step.placed ? (
                                <button
                                    type="button"
                                    className="flex w-full items-start gap-2 rounded-sm text-left outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                    onClick={() => onLocateSlot(step.slotKey)}
                                    aria-label={`${step.label}. ${status}. Select on PDF.`}
                                >
                                    <StepStatusIcon placed />
                                    <StepCopy
                                        sequence={step.sequence}
                                        label={step.label}
                                        status={status}
                                    />
                                </button>
                            ) : (
                                <div className="space-y-1.5">
                                    <div className="flex items-start gap-2">
                                        <StepStatusIcon placed={false} />
                                        <StepCopy
                                            sequence={step.sequence}
                                            label={step.label}
                                            status={status}
                                        />
                                    </div>
                                    {!readOnly ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-6 px-2 text-[11px]"
                                            onClick={() =>
                                                onPlaceSlot(
                                                    step.slotKey,
                                                    step.label,
                                                )
                                            }
                                        >
                                            Place on PDF
                                        </Button>
                                    ) : null}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

function StepStatusIcon({ placed }: { placed: boolean }) {
    if (placed) {
        return (
            <span
                aria-hidden
                className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400"
            >
                <Check className="size-2.5" />
            </span>
        );
    }

    return (
        <span
            aria-hidden
            className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
        >
            <AlertTriangle className="size-2.5" />
        </span>
    );
}

function StepCopy({
    sequence,
    label,
    status,
}: {
    sequence: number;
    label: string;
    status: string;
}) {
    return (
        <span className="min-w-0 flex-1">
            <span className="block text-xs font-medium text-foreground">
                {sequence} {label}
            </span>
            <span className="block text-[11px] text-muted-foreground">
                {status}
            </span>
        </span>
    );
}
