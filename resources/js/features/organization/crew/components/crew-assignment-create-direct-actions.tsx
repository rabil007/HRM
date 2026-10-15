import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS } from '@/features/organization/crew/lib/crew-assignment-create-actions';
import type { CrewAssignmentSingleSubmitAction } from '@/features/organization/crew/lib/crew-assignment-create-actions';

function DirectActionButton({
    label,
    tooltip,
    variant,
    disabled,
    processing,
    onClick,
}: {
    label: string;
    tooltip: string;
    variant: 'default' | 'outline';
    disabled: boolean;
    processing: boolean;
    onClick: () => void;
}): ReactElement {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant={variant}
                    disabled={disabled}
                    onClick={onClick}
                    className="h-11 rounded-xl px-6"
                >
                    {processing ? <Spinner className="mr-2" /> : null}
                    {label}
                </Button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs text-xs">
                {tooltip}
            </TooltipContent>
        </Tooltip>
    );
}

export function CrewAssignmentCreateDirectActions({
    canStart,
    processing,
    activeAction,
    onSubmit,
    startBlocked,
    draftBlocked,
}: {
    canStart: boolean;
    processing: boolean;
    activeAction: CrewAssignmentSingleSubmitAction | null;
    onSubmit: (action: CrewAssignmentSingleSubmitAction) => void;
    startBlocked: boolean;
    draftBlocked: boolean;
}): ReactElement {
    const startDisabled = processing || startBlocked;

    return (
        <>
            {canStart ? (
                <>
                    <DirectActionButton
                        label="Already Arrived"
                        tooltip={
                            CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.already_arrived
                        }
                        variant="default"
                        disabled={startDisabled}
                        processing={
                            processing && activeAction === 'already_arrived'
                        }
                        onClick={() => onSubmit('already_arrived')}
                    />
                    <DirectActionButton
                        label="Start Pre-Arrival"
                        tooltip={
                            CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.pre_arrival
                        }
                        variant="outline"
                        disabled={startDisabled}
                        processing={
                            processing && activeAction === 'pre_arrival'
                        }
                        onClick={() => onSubmit('pre_arrival')}
                    />
                </>
            ) : null}
            <DirectActionButton
                label="Save as Draft"
                tooltip={CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS.draft}
                variant="outline"
                disabled={processing || draftBlocked}
                processing={processing && activeAction === 'draft'}
                onClick={() => onSubmit('draft')}
            />
        </>
    );
}
