export type RequirementRepeatFieldGroup = {
    title: string;
    description: string;
    fields: string[];
};

export function resolveRequirementRepeatFieldGroups(): {
    copied: RequirementRepeatFieldGroup;
    review: RequirementRepeatFieldGroup;
    note: string;
} {
    return {
        copied: {
            title: 'Copied into the new requirement',
            description:
                'These details create a separate draft based on the source requirement.',
            fields: [
                'Client and project',
                'Location',
                'Position lines, requested headcount, and salary ranges',
                'Notes from the source requirement',
            ],
        },
        review: {
            title: 'Review before creating',
            description:
                'Confirm these values for the new draft. They are not reused from history automatically.',
            fields: [
                'Request received date',
                'Required-by date',
                'Priority',
                'Assigned recruiter',
            ],
        },
        note: 'Workflow history, approvals, attachments, and audit records stay with the original requirement. The result is a new draft with its own reference number.',
    };
}
