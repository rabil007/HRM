export type DocumentLifecycleAutomationSummary = {
    id: number;
    status: string;
    status_label: string;
    stage: string | null;
    stage_label: string | null;
    blocked_code: string | null;
    blocked_message: string | null;
    behavior_summary: string;
    workflow_request_id: number | null;
    signing_flow_id: number | null;
    can_retry: boolean;
    policy_snapshot: {
        schema_version: number | null;
        workflow_preset_id: number | null;
        workflow_preset_name: string | null;
        signing_preset_id: number | null;
        signing_preset_name: string | null;
    };
};
