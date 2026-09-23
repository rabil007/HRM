import { AlertTriangle, Plus, Layers, ArrowLeft } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { SimilarRequirementMatch } from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    matches: SimilarRequirementMatch[];
    onAddHeadcount: (targetRequirementId: number) => void;
    onCreateSeparateBatch: () => void;
    onReturnAndReview: () => void;
    isSubmitting?: boolean;
};

export function DuplicateDecisionDialog({
    open,
    onOpenChange,
    matches,
    onAddHeadcount,
    onCreateSeparateBatch,
    onReturnAndReview,
    isSubmitting = false,
}: Props) {
    const primaryMatch = matches[0];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-xl p-6">
                <DialogHeader className="space-y-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-amber-500/20 bg-amber-500/10 text-amber-500">
                            <AlertTriangle className="h-5 w-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-lg font-bold text-foreground">
                                Similar Active Requirement Detected
                            </DialogTitle>
                            <DialogDescription className="mt-0.5 text-xs text-muted-foreground">
                                An active or on-hold requirement already exists
                                for this client and position.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="my-4 space-y-3">
                    <div className="rounded-lg border border-amber-500/20 bg-amber-500/[0.04] p-4 text-xs text-amber-900 dark:text-amber-200">
                        To keep recruitment metrics clean and avoid
                        unintentional duplicate requisitions, you can either
                        increment the headcount on the existing requirement or
                        explicitly confirm this as an independent batch.
                    </div>

                    <div className="max-h-60 space-y-2 overflow-y-auto pr-1">
                        {matches.map((match) => (
                            <div
                                key={match.id}
                                className="flex flex-col gap-2 rounded-lg border border-border/80 bg-muted/20 p-3 text-xs"
                            >
                                <div className="flex items-center justify-between font-semibold text-foreground">
                                    <span className="font-mono font-bold text-primary">
                                        {match.requirement_number}
                                    </span>
                                    <Badge
                                        variant="outline"
                                        className={
                                            match.status === 'open'
                                                ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-500'
                                                : 'border-amber-500/20 bg-amber-500/10 text-amber-500'
                                        }
                                    >
                                        {match.status
                                            .replace('_', ' ')
                                            .toUpperCase()}
                                    </Badge>
                                </div>
                                <div className="grid grid-cols-2 gap-2 text-muted-foreground">
                                    <div>
                                        Client:{' '}
                                        <strong className="text-foreground">
                                            {match.client_name}
                                        </strong>
                                    </div>
                                    <div>
                                        Required By:{' '}
                                        <strong className="text-foreground">
                                            {match.required_by_date}
                                        </strong>
                                    </div>
                                </div>
                                <div className="text-[11px] text-muted-foreground">
                                    Matching positions:{' '}
                                    <span className="font-medium text-foreground">
                                        {match.matching_positions.join(', ')}
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <DialogFooter className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onReturnAndReview}
                        disabled={isSubmitting}
                        className="gap-1.5"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Return & Review
                    </Button>

                    <Button
                        type="button"
                        variant="secondary"
                        onClick={onCreateSeparateBatch}
                        disabled={isSubmitting}
                        className="gap-1.5"
                    >
                        <Layers className="h-4 w-4" />
                        Create Separate Batch
                    </Button>

                    {primaryMatch && (
                        <Button
                            type="button"
                            onClick={() => onAddHeadcount(primaryMatch.id)}
                            disabled={isSubmitting}
                            className="gap-1.5 bg-primary text-primary-foreground"
                        >
                            <Plus className="h-4 w-4" />
                            Add Headcount to {primaryMatch.requirement_number}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
