import { Info } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { WORKFLOW_HELP } from '@/features/organization/crew/lib/movement-workflow-help-content';
import type { MovementWorkflowHelpTopic } from '@/features/organization/crew/lib/movement-workflow-help-content';

export {
    WORKFLOW_HELP,
    movementWorkflowHelpTopicForAction,
    type MovementWorkflowHelpTopic,
} from '@/features/organization/crew/lib/movement-workflow-help-content';

export function MovementWorkflowHelp({
    topic,
    label,
}: {
    topic: MovementWorkflowHelpTopic;
    label?: string;
}): ReactElement {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7 shrink-0 text-muted-foreground"
                    aria-label={label ?? `Explain ${topic.replace('_', ' ')}`}
                >
                    <Info className="size-3.5" aria-hidden="true" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="max-w-xs text-sm text-muted-foreground"
            >
                {WORKFLOW_HELP[topic]}
            </PopoverContent>
        </Popover>
    );
}
