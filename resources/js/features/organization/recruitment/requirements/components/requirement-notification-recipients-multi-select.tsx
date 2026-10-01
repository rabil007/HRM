import type { WorkflowAssigneeOption } from '@/features/organization/documents/workflow/types';
import { WorkflowAssigneeMultiSelect } from '@/features/organization/documents/workflow/workflow-assignee-multi-select';
import type { UserOption } from '@/types/recruitment';

type Props = {
    options: UserOption[];
    assignedTo: string;
    value: number[];
    onChange: (ids: number[]) => void;
    error?: string;
};

export function RequirementNotificationRecipientsMultiSelect({
    options,
    assignedTo,
    value,
    onChange,
    error,
}: Props) {
    const assignedId = assignedTo ? Number(assignedTo) : null;
    const selectableOptions: WorkflowAssigneeOption[] = options
        .filter((user) => assignedId === null || user.id !== assignedId)
        .map((user) => ({
            id: user.id,
            name: user.name,
            email: user.email,
            can_review: false,
            can_approve: false,
        }));

    return (
        <div
            className="space-y-1.5"
            data-requirement-field="notification_recipient_ids"
        >
            <WorkflowAssigneeMultiSelect
                label="Additional notification recipients"
                options={selectableOptions}
                value={value}
                onChange={onChange}
                error={error}
            />
            <p className="text-[11px] text-muted-foreground">
                CC recipients receive updates but cannot approve. The requester
                is copied automatically.
            </p>
        </div>
    );
}
